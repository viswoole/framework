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
 * ③ 各分支 XA PREPARE——任一失败：全体 XA ROLLBACK + journal 清理（全量放弃，
 *    此时未执行任何 XA COMMIT，回滚所有分支不会造成部分提交）
 * ④ journal 标记 prepared（提交意图确立）
 * ⑤ 各分支 XA COMMIT——任一失败：保留 journal 行并抛出，已提交分支无法撤回、
 *    未决分支禁止静默回滚（那会复刻逐连接 commit 的部分提交问题），
 *    交由恢复任务按 journal 意图补齐
 * ⑥ journal 删除行（失败不阻断：残留行会被下次恢复任务自愈清理）
 *
 * 崩溃窗口分析：
 * - ②③ 之间崩溃：journal 有 prepare_start 行，分支未 prepared 或部分 prepared，
 *   恢复任务统一 XA ROLLBACK；
 * - ④⑤ 之间或 ⑤ 中途崩溃：journal 有 prepared 行，恢复任务对未决分支补 XA COMMIT；
 * - 恢复操作以 xid 寻址、XAER_NOTA 被容忍，全程幂等，可安全重试。
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
      // ③ 各分支预提交
      foreach ($branches as $i => $branch) {
        self::inject('prepare', $i);
        XaDriver::execute($branch['connect'], "XA PREPARE '{$branch['xid']}'");
        $context->markBranch($branch['connect'], XaBranchState::Prepared);
      }
      // ④ 提交意图确立
      $context->journal()->markPrepared($gtrid);
    } catch (Throwable $e) {
      // 尚未执行任何 XA COMMIT，全体回滚不会造成部分提交
      self::abortAll($context, $branches);
      throw new DbException(
        "XA 事务提交失败，已全体回滚（gtrid: {$gtrid}）：{$e->getMessage()}", 0, null, $e
      );
    }
    // ⑤ 逐分支提交：任何失败保留 journal 行交由恢复任务重试（见类注释崩溃窗口分析）
    foreach ($branches as $i => $branch) {
      try {
        self::inject('commit', $i);
        XaDriver::execute($branch['connect'], "XA COMMIT '{$branch['xid']}'");
      } catch (Throwable $e) {
        throw new DbException(
          "XA 事务 XA COMMIT 失败，事务停留未决状态，将由恢复任务按提交意图补齐"
          . "（gtrid: {$gtrid}，xid: {$branch['xid']}）：{$e->getMessage()}", 0, null, $e
        );
      }
      $context->markBranch($branch['connect'], XaBranchState::Done);
    }
    // ⑥ 清理 journal（失败不阻断：恢复任务对已终结 xid 得到 XAER_NOTA 后自愈删行）
    if ($context->journalRowExists()) {
      try {
        $context->journal()->remove($gtrid);
      } catch (Throwable) {
        // 残留行在下次恢复时发现无未决分支后删除，不影响正确性
      }
      $context->setJournalRowExists(false);
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
