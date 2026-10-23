<?php

declare(strict_types=1);

/** XA 分支回滚协议：负责状态流转；连接淘汰与 journal 生命周期由调用方管理。 */

namespace Viswoole\Database\Transaction;

use Throwable;

final class XaBranchRollback
{
  /**
   * 尝试终结分支，失败保留最后确认的状态，禁止误标为 Done。
   * @param XaContext $context XA 上下文
   * @param object $connection 分支连接
   * @param string $xid 分支标识（框架生成）
   * @return bool 已回滚或已不存在时为 true，否则由调用方淘汰连接/保留恢复日志
   */
  public static function attempt(XaContext $context, object $connection, string $xid): bool
  {
    if ($context->branchStateOf($connection) === XaBranchState::Done) return true;
    try {
      // MySQL 禁止 ACTIVE 分支直接 ROLLBACK，必须先结束业务阶段。
      if ($context->branchStateOf($connection) === XaBranchState::Active) {
        XaDriver::execute($connection, "XA END '$xid'");
        $context->markBranch($connection, XaBranchState::Ended);
      }
      XaDriver::execute($connection, "XA ROLLBACK '$xid'");
    } catch (Throwable $exception) {
      if (!XaDriver::isNotExists($exception)) {
        error_log("[Viswoole XA] 分支回滚失败（xid: {$xid}）：{$exception->getMessage()}");
        return false;
      }
    }
    $context->markBranch($connection, XaBranchState::Done);
    return true;
  }
}
