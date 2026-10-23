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

namespace Viswoole\Database;

use Throwable;
use Viswoole\Log\Facade\Log;

/**
 * 数据库子系统日志出口
 *
 * 事务清理、连接淘汰、崩溃恢复等兜底路径的日志调用必须保证不向外抛异常：
 * 日志驱动故障不能打断分支回滚/连接归还流程，更不能在异常展开过程中
 * 顶替原始数据库异常（那会让调用方与运维看到的是日志错误而非真实故障）。
 *
 * 仅限上述兜底路径使用；正常业务路径的日志请直接使用 Log 门面，
 * 让驱动异常正常向上传播（调用方有能力处理时吞异常反而是掩盖故障）。
 */
final class DbLog
{
  /**
   * 尽力写入一条日志，写入失败时降级 error_log 留痕且不向外抛异常
   *
   * @param string $level 日志级别（error|warning|info|debug）
   * @param string $message 消息内容
   */
  public static function write(string $level, string $message): void
  {
    try {
      Log::write($level, $message);
    } catch (Throwable $exception) {
      // 降级 error_log 尽力留痕：stderr 在守护化下同样可能被重定向丢失，
      // 但优于静默吞掉故障证据；降级事件本身必须包含被吞掉的原始消息
      error_log(
        "[Viswoole Database] 日志驱动写入失败（{$level}）：{$exception->getMessage()}；原始消息：{$message}"
      );
    }
  }
}
