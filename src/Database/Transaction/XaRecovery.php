<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Database\Transaction;

use Throwable;
use Viswoole\Core\App;
use Viswoole\Database\Channel;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\DbManager;
use function echo_log;

/**
 * XA 崩溃恢复执行器
 *
 * 依据 journal 行的提交意图，对数据库服务端残留的未决（prepared）分支
 * 执行终结操作，收敛崩溃遗留的 in-doubt 事务：
 * - journal 行为 prepared：提交意图已确立（PREPARE 全部成功后崩溃），补 XA COMMIT；
 * - journal 行为 prepare_start：提交未确立（PREPARE 前或中途崩溃），补 XA ROLLBACK；
 * - 未决分支以 XA RECOVER 实际查询结果为准，不信任 journal 的分支清单
 *   （崩溃可能发生在任何中间态，服务端状态才是权威）；
 * - 无 journal 行对应的未决 xid：跳过并告警（框架只恢复自己创建的事务，
 *   他方 XA 事务需人工处理）。
 *
 * 触发时机：每个 worker 进程启动时（DbService 注册的 workerStart 钩子）。
 * 幂等性：终结语句以 xid 寻址，已终结的 xid 返回 XAER_NOTA 被容忍，
 * 多 worker / 多实例并发恢复与手动重跑均安全。
 *
 * 活性判定：prepare_start 行仅代表"提交尚未确立"，行仍可能被活跃协调者持有。
 * 处置需同时满足：超过 created_at 冷却期、且心跳停跳（协调者关键阶段心跳刷新
 * 超过 TTL，见 XaJournal::heartbeat）——防止慢 PREPARE/网络重试停顿中的活跃
 * 事务被误删恢复依据或误回滚分支。处置前还会重读行状态，行已翻转为 prepared
 * 时按提交意图终结（收敛"读取旧行后协调者复活确立意图"的竞态窗口）。
 */
final class XaRecovery
{
  /**
   * prepare_start 行的处置冷却期（秒）
   *
   * prepare_start 行可能在事务提交进行中被读出（journal 写入与 PREPARE/COMMIT
   * 之间无全局互斥）：此刻其分支可能已 prepared 但尚不该被终结（事务还活着），
   * 或分支尚未 prepared（XA RECOVER 查不到）。处置此类"年轻"行会误删
   * 活跃事务的恢复依据、甚至误回滚其分支。冷却期内只跳过不处置，
   * 下次恢复（行仍在且已过冷却期）才安全收敛。
   * prepared 行无此竞态：对其未决分支补 XA COMMIT 与 commit() 的
   * 正常路径幂等重合，可即时处置。
   *
   * 冷却期是心跳判定的兜底：无心跳信息（旧表未迁移、协调者尚未首次刷新）的
   * 行仅按冷却期判定；有心跳的行还要求心跳停跳（HEARTBEAT_TTL_SECONDS）。
   */
  private const int PREPARE_START_GRACE_SECONDS = 60;

  /**
   * journal 行心跳停跳判定阈值（秒）
   *
   * 协调者在每个分支的 PREPARE/COMMIT 前刷新心跳（XaJournal::heartbeat），
   * 心跳距今超过该阈值即认定协调者已死（presumed dead）：正常提交路径的
   * 关键阶段间隔为单条语句往返，远小于该值；协调者崩溃后心跳自然停跳。
   * 与 PREPARE_START_GRACE_SECONDS 一致取 60s，兼顾误杀率与悬挂时长。
   */
  private const int HEARTBEAT_TTL_SECONDS = 60;

  /**
   * 执行崩溃恢复（journal 为空时仅一次探测查询，常规启动开销可忽略）
   *
   * @param string|null $onlyGtrid 仅处置指定 gtrid 的行（xa:recover --xid），
   *                               null 处置全部；未命中过滤的行与其分支保持原样
   * @param bool $dryRun 仅扫描并报告将执行的动作，不终结分支、不删除 journal 行（xa:recover --dry-run）
   * @throws Throwable journal 读取失败时向上抛出（调用方负责告警，不影响服务启动）
   */
  public static function run(?string $onlyGtrid = null, bool $dryRun = false): void
  {
    $journal = XaJournal::fromConfig();
    $rows = $journal->all();
    if ($rows === []) return;
    /** @var array<string,array{state:int,branches:string[],created_at:string,heartbeat_at:string|null,blocked?:bool}> $rows */
    // 过滤前的全量行（供 recoverXid 区分"被 --xid 过滤跳过"与"确实无记录"）
    $allRows = $rows;
    // xid 过滤：仅保留命中的行（其余行与其分支保持原样）
    if ($onlyGtrid !== null) {
      foreach ($rows as $gtrid => $row) {
        if ($gtrid !== $onlyGtrid) unset($rows[$gtrid]);
      }
      if ($rows === []) {
        echo_log("XA 恢复：journal 中不存在 gtrid 为 {$onlyGtrid} 的行", 'XA', backtrace: 0);
        return;
      }
    }
    // 过滤不满足处置条件的 prepare_start 行：不处置、不删除，留待下次恢复。
    // 处置条件 = 超过 created_at 冷却期 且 心跳已停跳（无心跳信息时仅按冷却期）
    foreach ($rows as $gtrid => $row) {
      if ($row['state'] !== XaJournal::STATE_PREPARE_START) continue;
      if (self::isBeyondGrace($row['created_at']) && !self::isHeartbeatAlive($row['heartbeat_at'] ?? null)) {
        continue;
      }
      echo_log(
        "XA 恢复：journal 行（gtrid: {$gtrid}）处于冷却期内或心跳仍活跃，跳过待下次恢复",
        'XA',
        backtrace: 0
      );
      unset($rows[$gtrid]);
    }
    // 处置前重读 prepare_start 行状态：all() 读取与处置之间协调者可能已恢复并
    // 确立提交意图（心跳停跳判定通过后行又翻转为 prepared），按最新意图终结，
    // 将"误按回滚意图处置活跃事务"的竞态窗口压缩到重读-执行的毫秒级间隙
    foreach ($rows as $gtrid => $row) {
      if ($row['state'] !== XaJournal::STATE_PREPARE_START) continue;
      try {
        $state = $journal->stateOf($gtrid);
      } catch (Throwable $e) {
        echo_log(
          "XA 恢复：journal 行状态重读失败，保留待下次恢复（gtrid: {$gtrid}，{$e->getMessage()}）",
          'XA',
          backtrace: 0
        );
        $rows[$gtrid]['blocked'] = true;
        continue;
      }
      if ($state === null) {
        // 行已被并发恢复任务删除：本轮不处置、结束时不重复删行
        unset($rows[$gtrid]);
        continue;
      }
      if ($state !== $row['state']) $rows[$gtrid]['state'] = $state;
    }
    if ($dryRun) {
      // 先输出基于 journal 意图的处置计划，随后扫描各通道报告实际未决分支
      self::reportPlan($rows);
    }
    $db = App::factory()->make(DbManager::class);
    foreach ($db->getChannels() as $channel) {
      // 仅扫描可能持有 XA 分支的通道：跳过既消除非 MySQL 通道的
      // 扫描告警噪音，也避免其扫描失败永久阻塞 journal 行清理
      if (!self::canHostXaBranches($channel)) continue;
      self::recoverChannel($channel, $rows, $dryRun, $allRows);
    }
    if ($dryRun) return; // dry-run 到此为止：终结语句与 journal 删除均已按计划输出而未执行
    // 仅删除所有通道均确认无未决分支的行；任一通道扫描失败则保留待下次
    foreach ($rows as $gtrid => $row) {
      if (!empty($row['blocked'])) continue;
      $journal->remove($gtrid);
    }
  }

  /**
   * 输出 dry-run 的 journal 行处置计划（基于意图，实际未决分支在扫描阶段逐个报告）
   *
   * @param array<string,array{state:int,branches:string[],created_at:string,blocked?:bool}> $rows 待处置的 journal 行
   */
  private static function reportPlan(array $rows): void
  {
    if ($rows === []) {
      echo_log('XA 恢复（dry-run）：无可处置的 journal 行', 'XA', backtrace: 0);
      return;
    }
    foreach ($rows as $gtrid => $row) {
      $action = $row['state'] === XaJournal::STATE_PREPARED ? 'XA COMMIT' : 'XA ROLLBACK';
      echo_log(
        "XA 恢复（dry-run）：gtrid {$gtrid} 状态 {$row['state']}，将对未决分支执行 {$action}，随后删除该 journal 行",
        'XA',
        backtrace: 0
      );
    }
  }

  /**
   * 判断通道是否可能持有框架创建的 XA 分支
   *
   * PDO 通道按驱动类型判定：XA START 在非 MySQL 数据库（SQLite/PostgreSQL 等）
   * 上必然失败，分支从未建立——扫描此类通道只会产生告警噪音并阻塞 journal 行
   * 清理。自定义通道能力未知，保守起见仍尝试扫描，由扫描失败时的阻塞机制兜底。
   *
   * @param Channel $channel 待判断的通道
   * @return bool 可能持有返回 true
   */
  private static function canHostXaBranches(Channel $channel): bool
  {
    if ($channel instanceof PDOChannel) {
      return $channel->type === DriverType::MYSQL;
    }
    return true;
  }

  /**
   * 判断 journal 行是否已超过处置冷却期
   *
   * @param string $createdAt 行写入时间（journal 表 created_at，数据库时区）
   * @return bool 已超过返回 true；解析失败按未超期处理（保守处置）
   */
  private static function isBeyondGrace(string $createdAt): bool
  {
    $timestamp = strtotime($createdAt);
    return $timestamp !== false && (time() - $timestamp) >= self::PREPARE_START_GRACE_SECONDS;
  }

  /**
   * 判断 journal 行心跳是否仍活跃（协调者最近在关键阶段刷新过）
   *
   * @param string|null $heartbeatAt 行心跳时间（null = 旧表未迁移或协调者尚未
   *                                 首次刷新——无心跳信息，仅按冷却期判定）
   * @return bool 仍活跃返回 true（该行本轮不处置）；解析失败按停跳处理
   *              （与冷却期解析失败策略一致：宁可误判停跳进入重读核对，不无限搁置）
   */
  private static function isHeartbeatAlive(?string $heartbeatAt): bool
  {
    if ($heartbeatAt === null) return false;
    $timestamp = strtotime($heartbeatAt);
    if ($timestamp === false) return false;
    return (time() - $timestamp) < self::HEARTBEAT_TTL_SECONDS;
  }

  /**
   * 恢复单个通道上的未决分支
   *
   * 通道不可用（连接失败或数据库不支持 XA）时阻塞全部行：
   * 无法确认该通道不存在未决分支前删除 journal 行，可能留下永久未决事务。
   *
   * @param Channel $channel 数据库通道
   * @param array<string,array{state:int,branches:string[],created_at:string,heartbeat_at:string|null,blocked?:bool}> $rows journal 行（引用更新 blocked 标记与重读后的 state）
   * @param bool $dryRun true 时仅报告未决分支与计划动作，不执行终结语句
   * @param array<string,array{state:int,branches:string[],created_at:string,heartbeat_at:string|null}> $allRows 过滤前的全量 journal 行（区分"被过滤跳过"与"无记录"）
   */
  private static function recoverChannel(Channel $channel, array &$rows, bool $dryRun = false, array $allRows = []): void
  {
    try {
      $connect = $channel->pop('write');
      // 池借出超时返回 false（Swoole 语义）：抛出走 catch 的兜底路径，
      // 且不得把 false 传入归还路径污染连接池
      if (!is_object($connect)) {
        throw new DbException('XA 恢复：通道连接借出失败（可能连接池超时）');
      }
      try {
        $inDoubtXids = XaDriver::recover($connect);
        foreach ($inDoubtXids as $xid) {
          self::recoverXid($connect, $xid, $rows, $dryRun, $allRows);
        }
      } finally {
        $channel->put($connect);
      }
    } catch (Throwable $e) {
      echo_log(
        "XA 恢复：通道 " . $channel::class . " 扫描失败，journal 行保留待下次恢复（{$e->getMessage()}）",
        'XA',
        backtrace: 0
      );
      foreach ($rows as &$row) $row['blocked'] = true;
      unset($row);
    }
  }

  /**
   * 恢复单个未决 xid（按 journal 意图补终结语句）
   *
   * @param object $connect 通道连接（终结语句以 xid 寻址，可用任意连接执行）
   * @param string $xid 未决分支 xid
   * @param array<string,array{state:int,branches:string[],created_at:string,heartbeat_at:string|null,blocked?:bool}> $rows journal 行（引用更新 blocked 标记）
   * @param bool $dryRun true 时仅输出计划动作，不执行终结语句
   * @param array<string,array{state:int,branches:string[],created_at:string,heartbeat_at:string|null}> $allRows 过滤前的全量 journal 行
   */
  private static function recoverXid(
    object $connect,
    string $xid,
    array  &$rows,
    bool   $dryRun = false,
    array  $allRows = []
  ): void {
    $gtrid = self::matchJournalRow($rows, $xid);
    if ($gtrid === null) {
      // 二级匹配：journal 有记录但被 --xid 过滤排除——明确提示"已过滤跳过"，
      // 避免与"确实无记录需人工处理"混淆（后者可能意味着 journal 丢失等异常）
      $skippedGtrid = $allRows === [] ? null : self::matchJournalRow($allRows, $xid);
      if ($skippedGtrid !== null) {
        echo_log(
          "XA 恢复：xid {$xid} 归属事务（gtrid: {$skippedGtrid}）已过滤跳过，本次不处置",
          'XA',
          backtrace: 0
        );
        return;
      }
      // 不属于框架管理的事务（或 journal 丢失）：只告警不处理，避免误伤他方事务
      echo_log(
        "XA 恢复：发现无 journal 记录的未决事务，已跳过请人工处理（xid: {$xid}）",
        'XA',
        backtrace: 0
      );
      return;
    }
    $action = $rows[$gtrid]['state'] === XaJournal::STATE_PREPARED ? 'XA COMMIT' : 'XA ROLLBACK';
    // 恢复路径的 xid 来自服务端 XA RECOVER（库内读回值可能被篡改）：
    // 前缀命中 journal gtrid 后的尾部片段同样必须过白名单，防止注入终结语句。
    // 白名单与 XaJournal::assertValidGtrid 的 gtrid 字符集一致（框架生成的
    // 分支 xid = gtrid + '-b' + 序号，必然合法）
    if (!preg_match('/^[0-9a-zA-Z._-]{1,64}$/', $xid)) {
      echo_log(
        "XA 恢复：xid 含非法字符，跳过请人工处理（xid: " . addcslashes($xid, "\0..\37") . "）",
        'XA',
        backtrace: 0
      );
      return;
    }
    if ($dryRun) {
      echo_log("XA 恢复（dry-run）：将对未决分支 {$xid} 执行 {$action}", 'XA', backtrace: 0);
      return;
    }
    try {
      XaDriver::execute($connect, "{$action} '{$xid}'");
    } catch (Throwable $e) {
      if (XaDriver::isNotExists($e)) return; // 已被并发恢复终结，幂等达成
      echo_log(
        "XA 恢复：{$action} 失败，journal 行保留待下次恢复（xid: {$xid}，{$e->getMessage()}）",
        'XA',
        backtrace: 0
      );
      $rows[$gtrid]['blocked'] = true;
    }
  }

  /**
   * 按 gtrid 前缀匹配未决 xid 所属的 journal 行
   *
   * @param array<string,array{state:int,branches:string[],blocked?:bool}> $rows journal 行
   * @param string $xid 未决分支 xid
   * @return string|null 匹配的 gtrid，无匹配返回 null
   */
  private static function matchJournalRow(array $rows, string $xid): ?string
  {
    foreach (array_keys($rows) as $gtrid) {
      if (str_starts_with($xid, $gtrid . '-')) return (string)$gtrid;
    }
    return null;
  }
}
