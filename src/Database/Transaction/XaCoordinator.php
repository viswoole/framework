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

use Closure;
use Throwable;
use Viswoole\Database\Exception\DbException;

/**
 * XA 两阶段提交编排器
 *
 * 协议时序（commit）：
 * ① 各分支 XA END（分支不再接受业务语句）
 * ② journal 写入 prepare_start 行——必须先于任何 PREPARE：保证"分支 prepared
 *    则 journal 必有行"，恢复决策因此完全确定，无需启发式猜测
 * ③ 各分支 XA PREPARE——每分支 PREPARE 前刷新 journal 心跳（向恢复任务证明
 *    协调者活跃，防止慢 PREPARE 停顿期间 prepare_start 行被误处置）；
 *    任一失败：全体 XA ROLLBACK + journal 清理（全量放弃，此时未执行任何
 *    XA COMMIT，回滚所有分支不会造成部分提交）
 * ④ journal 标记 prepared（提交意图确立）——更新 0 行说明行已被恢复任务删除
 *    （提交意图丢失），立即中止提交并全体回滚
 * ⑤ 各分支 XA COMMIT——每分支前刷新心跳；任一失败：保留 journal 行并抛出，
 *    已提交分支无法撤回、未决分支禁止静默回滚（那会复刻逐连接 commit 的
 *    部分提交问题），交由恢复任务按 journal 意图补齐；XAER_NOTA 例外——
 *    分支已被并发恢复任务终结，容忍前核对 journal 行状态（行已删除或为
 *    prepared 才容忍；仍为 prepare_start 说明分支被按回滚意图终结，
 *    容忍会使调用方误判"提交达成"造成静默数据错乱）
 * ⑥ journal 删除行（失败不阻断：残留行会被下次恢复任务自愈清理）
 *
 * 崩溃窗口分析：
 * - ②③ 之间崩溃：journal 有 prepare_start 行，分支未 prepared 或部分 prepared，
 *   恢复任务统一 XA ROLLBACK；
 * - ④⑤ 之间或 ⑤ 中途崩溃：journal 有 prepared 行，恢复任务对未决分支补 XA COMMIT；
 * - 恢复操作以 xid 寻址、XAER_NOTA 被容忍，全程幂等，可安全重试。
 *
 * 残余风险（presumed-dead 语义的固有边界）：协调者在单条语句内停顿超过恢复
 * 侧心跳 TTL 时，恢复任务仍可能在"重读行状态 → 执行终结语句"的毫秒级间隙
 * 处置仍活跃的分支；彻底消除需 lease/fencing 协议，超出 journal 单行模型。
 */
final class XaCoordinator
{
  /**
   * 故障注入钩子（仅测试使用）
   *
   * 签名：fn(string $stage, int $branchIndex): void，在指定阶段的指定分支
   * 操作前调用，钩子抛出的异常即模拟该操作失败。stage 取值：end|prepare|commit。
   *
   * @var Closure|null
   */
  public static ?Closure $faultInjector = null;

  /**
   * 执行两阶段提交（ConnectManager::commit 的 XA 首层路径）
   *
   * @param XaContext $context XA 事务上下文
   * @param array<int,array{connect:object,xid:string}> $branches 分支列表
   * @throws DbException PREPARE 前失败（已全体回滚）或 XA COMMIT 失败（停留未决）时抛出
   */
  public static function commit(XaContext $context, array $branches): void
  {
    // 无分支（开启后未执行任何 SQL）：无服务端事务需要终结，
    // journal 写入/清理均为无效 I/O，直接返回
    if ($branches === []) return;
    self::prepareAll($context, $branches);
    self::commitAll($context, $branches);
    self::cleanupJournal($context);
  }

  /**
   * 提交准备阶段（协议 ①②③④）：分支终止 → 意图持久化 → 逐分支预提交 → 意图确立
   *
   * 任何失败都尚未执行 XA COMMIT，全体回滚不会造成部分提交。
   *
   * @param XaContext $context XA 事务上下文
   * @param array<int,array{connect:object,xid:string}> $branches 分支列表
   * @throws DbException 准备阶段失败（已全体回滚）时抛出
   */
  private static function prepareAll(XaContext $context, array $branches): void
  {
    $gtrid = $context->gtrid;
    try {
      // ① 分支终止业务语句
      foreach ($branches as $i => $branch) {
        self::inject('end', $i);
        XaDriver::execute($branch['connect'], "XA END '{$branch['xid']}'");
        $context->markBranch($branch['connect'], XaBranchState::Ended);
      }
      // ② 提交意图起点（先于任何 PREPARE 持久化）
      $context->journal()->recordPrepareStart($gtrid, array_column($branches, 'xid'));
      $context->setJournalRowExists(true);
      // ③ 各分支预提交（每分支前刷新心跳，见类注释）
      foreach ($branches as $i => $branch) {
        $context->journal()->heartbeat($gtrid);
        self::inject('prepare', $i);
        XaDriver::execute($branch['connect'], "XA PREPARE '{$branch['xid']}'");
        $context->markBranch($branch['connect'], XaBranchState::Prepared);
      }
      // ④ 提交意图确立。更新 0 行 = 行已被恢复任务删除（提交意图丢失）：
      //    此刻未执行任何 COMMIT，全体回滚安全；若继续推进 ⑤，提交结果
      //    取决于恢复任务是否处置过分支——悬挂或静默回滚皆不可控
      if (!$context->journal()->markPrepared($gtrid)) {
        throw new DbException("提交意图行已被恢复任务移除（gtrid: {$gtrid}）");
      }
    } catch (Throwable $e) {
      // 尚未执行任何 XA COMMIT，全体回滚不会造成部分提交
      self::abortAll($context, $branches);
      throw new DbException(
        "XA 事务提交失败，已全体回滚（gtrid: {$gtrid}）：{$e->getMessage()}",
        0,
        null,
        $e
      );
    }
  }

  /**
   * 提交阶段（协议 ⑤）：逐分支 XA COMMIT，失败保留 journal 行交由恢复任务补齐
   *
   * @param XaContext $context XA 事务上下文
   * @param array<int,array{connect:object,xid:string}> $branches 分支列表
   * @throws DbException XA COMMIT 失败（事务停留未决）时抛出
   */
  private static function commitAll(XaContext $context, array $branches): void
  {
    $gtrid = $context->gtrid;
    foreach ($branches as $i => $branch) {
      try {
        // 心跳刷新失败（journal 不可达）时停留未决抛出：意图已确立为
        // prepared，恢复任务会按提交意图补齐；静默继续将绕过故障感知
        $context->journal()->heartbeat($gtrid);
      } catch (Throwable $e) {
        throw new DbException(
          "XA 事务提交心跳刷新失败，事务停留未决状态，将由恢复任务按提交意图补齐"
            . "（gtrid: {$gtrid}，xid: {$branch['xid']}）：{$e->getMessage()}",
          0,
          null,
          $e
        );
      }
      try {
        self::inject('commit', $i);
        XaDriver::execute($branch['connect'], "XA COMMIT '{$branch['xid']}'");
      } catch (Throwable $e) {
        if (XaDriver::isNotExists($e)) {
          // XAER_NOTA：该分支已被并发恢复任务终结。容忍前核对 journal 行状态
          // 消除二义性（见类注释 ⑤），核对通过后视为提交达成，继续下一分支
          self::assertBranchNotRolledBack($context, $gtrid);
          $context->markBranch($branch['connect'], XaBranchState::Done);
          continue;
        }
        throw new DbException(
          "XA 事务 XA COMMIT 失败，事务停留未决状态，将由恢复任务按提交意图补齐"
            . "（gtrid: {$gtrid}，xid: {$branch['xid']}）：{$e->getMessage()}",
          0,
          null,
          $e
        );
      }
      $context->markBranch($branch['connect'], XaBranchState::Done);
    }
  }

  /**
   * journal 清理阶段（协议 ⑥）：删除已终结事务的意图行
   *
   * @param XaContext $context XA 事务上下文
   */
  private static function cleanupJournal(XaContext $context): void
  {
    // 失败不阻断：恢复任务对已终结 xid 得到 XAER_NOTA 后自愈删行
    if (!$context->journalRowExists()) return;
    try {
      $context->journal()->remove($context->gtrid);
    } catch (Throwable) {
      // 残留行在下次恢复时发现无未决分支后删除，不影响正确性
    }
    $context->setJournalRowExists(false);
  }

  /**
   * 核对 XAER_NOTA 的分支未被按回滚意图终结（提交侧二义性消除）
   *
   * XAER_NOTA 只表明"分支在服务端已终结"，终结方向由 journal 行状态决定：
   * - 行已删除 / 状态 prepared：恢复任务按提交意图终结并自愈清理
   *   （prepared 意图在 ④ 已确立）→ 幂等成立，容忍为提交达成；
   * - 状态 prepare_start：恢复任务按回滚意图终结了该分支 → 抛出，
   *   禁止静默"提交达成"（调用方据此感知失败，而非带着已回滚的数据继续）。
   * journal 不可达时无法核对：保守容忍并告警——提交意图在 ④ 已确立为
   * prepared，与恢复侧按 prepared 行补 COMMIT 的路径对称，误判概率可忽略。
   *
   * @param XaContext $context XA 事务上下文
   * @param string $gtrid 全局事务 ID
   * @throws DbException 行状态仍为 prepare_start（分支已被回滚）时抛出
   */
  private static function assertBranchNotRolledBack(XaContext $context, string $gtrid): void
  {
    try {
      $state = $context->journal()->stateOf($gtrid);
    } catch (Throwable $e) {
      echo_log(
        "XA 提交：XAER_NOTA 后 journal 核对失败，按提交达成容忍（gtrid: {$gtrid}，{$e->getMessage()}）",
        'XA',
        backtrace: 0
      );
      return;
    }
    if ($state === XaJournal::STATE_PREPARE_START) {
      throw new DbException(
        "XA 事务分支已被恢复任务按回滚意图终结，提交未达成（gtrid: {$gtrid}）"
      );
    }
  }

  /**
   * 放弃整个 XA 事务（ConnectManager::rollBack / rollBackAll / 析构兜底路径）
   *
   * 尽力回滚全部分支，不向外抛出异常：回滚路径本身可能在异常展开栈或
   * 析构阶段执行，此时再抛出会掩盖原始异常甚至引发致命错误。
   *
   * @param XaContext $context XA 事务上下文
   * @param array<int,array{connect:object,xid:string}> $branches 分支列表
   */
  public static function rollBack(XaContext $context, array $branches): void
  {
    self::abortAll($context, $branches);
  }

  /**
   * 全量回滚全部分支并清理 journal 行
   *
   * @param XaContext $context XA 事务上下文
   * @param array<int,array{connect:object,xid:string}> $branches 分支列表
   */
  private static function abortAll(XaContext $context, array $branches): void
  {
    foreach ($branches as $branch) {
      if ($context->branchStateOf($branch['connect']) === XaBranchState::Done) continue;
      try {
        XaDriver::execute($branch['connect'], "XA ROLLBACK '{$branch['xid']}'");
      } catch (Throwable) {
        // 回滚失败：连接大概率已失效，服务端会在断连时自行回滚，
        // 连接交由连接池健康检查淘汰——不阻断其余分支的回滚
      }
      $context->markBranch($branch['connect'], XaBranchState::Done);
    }
    if ($context->journalRowExists()) {
      try {
        $context->journal()->remove($context->gtrid);
      } catch (Throwable) {
        // journal 不可达：残留 prepare_start 行会被恢复任务按回滚意图处理后删除
      }
      $context->setJournalRowExists(false);
    }
  }

  /**
   * 触发故障注入钩子（未设置或阶段不匹配时无操作）
   *
   * @param string $stage 阶段名：end|prepare|commit
   * @param int $branchIndex 分支序号
   */
  private static function inject(string $stage, int $branchIndex): void
  {
    if (self::$faultInjector !== null) {
      (self::$faultInjector)($stage, $branchIndex);
    }
  }
}
