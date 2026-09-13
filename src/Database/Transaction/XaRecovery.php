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
 */
final class XaRecovery
{
  /**
   * 执行崩溃恢复（journal 为空时仅一次探测查询，常规启动开销可忽略）
   *
   * @throws Throwable journal 读取失败时向上抛出（调用方负责告警，不影响服务启动）
   */
  public static function run(): void
  {
    $journal = XaJournal::fromConfig();
    $rows = $journal->all();
    if ($rows === []) return;
    /** @var array<string,array{state:int,branches:string[],blocked?:bool}> $rows */
    $db = App::factory()->make(DbManager::class);
    foreach ($db->getChannels() as $channel) {
      self::recoverChannel($channel, $rows);
    }
    // 仅删除所有通道均确认无未决分支的行；任一通道扫描失败则保留待下次
    foreach ($rows as $gtrid => $row) {
      if (!empty($row['blocked'])) continue;
      $journal->remove($gtrid);
    }
  }

  /**
   * 恢复单个通道上的未决分支
   *
   * 通道不可用（连接失败或数据库不支持 XA）时阻塞全部行：
   * 无法确认该通道不存在未决分支前删除 journal 行，可能留下永久未决事务。
   *
   * @param Channel $channel 数据库通道
   * @param array<string,array{state:int,branches:string[],blocked?:bool}> $rows journal 行（引用更新 blocked 标记）
   */
  private static function recoverChannel(Channel $channel, array &$rows): void
  {
    try {
      $connect = $channel->pop('write');
      try {
        $inDoubtXids = XaDriver::recover($connect);
        foreach ($inDoubtXids as $xid) {
          self::recoverXid($connect, $xid, $rows);
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
   * @param array<string,array{state:int,branches:string[],blocked?:bool}> $rows journal 行（引用更新 blocked 标记）
   */
  private static function recoverXid(object $connect, string $xid, array &$rows): void
  {
    $gtrid = self::matchJournalRow($rows, $xid);
    if ($gtrid === null) {
      // 不属于框架管理的事务（或 journal 丢失）：只告警不处理，避免误伤他方事务
      echo_log(
        "XA 恢复：发现无 journal 记录的未决事务，已跳过请人工处理（xid: {$xid}）", 'XA', backtrace: 0
      );
      return;
    }
    $action = $rows[$gtrid]['state'] === XaJournal::STATE_PREPARED ? 'XA COMMIT' : 'XA ROLLBACK';
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
