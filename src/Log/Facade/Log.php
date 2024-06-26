<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Log\Facade;

use Stringable;
use Viswoole\Core\Facade;
use Viswoole\Log\Contract\DriveInterface;
use Viswoole\Log\LogManager;

/**
 * 日志门面类
 *
 * @method static void mixed(string $level, string|Stringable $message, array $context = []) 记录具有任意级别的日志。
 * @method static void alert(string|Stringable $message, array $context = []) 必须立即采取行动。
 * @method static void error(string|Stringable $message, array $context = []) 不需要立即采取行动的运行时错误，但通常应记录和监视。
 * @method static void warning(string|Stringable $message, array $context = []) 不是错误的异常情况。
 * @method static void info(string|Stringable $message, array $context = []) 有趣的事件。
 * @method static void debug(string|Stringable $message, array $context = []) 详细的调试信息。
 * @method static void sql(string|Stringable $message, array $context = []) SQL日志。
 * @method static void task(string|Stringable $message, array $context = []) 任务日志。
 * @method static void write(string $level, Stringable|string $message, array $context = []) 直接写入日志
 * @method static void record(string $level, Stringable|string $message, array $context = []) 缓存日志
 * @method static bool save(array $logRecords) 保存日志（无需手动调用, 协程结束会自动调用）
 * @method static bool clearRecord() 清除缓存日志
 * @method static array getRecord() 获取缓存日志
 * @method static DriveInterface channel(string $name) 设置日志通道
 * @method static bool hasChannel(string $name) 判断通道是否存在
 */
class Log extends Facade
{

  /**
   * @inheritDoc
   */
  #[\Override] protected static function getMappingClass(): string
  {
    return LogManager::class;
  }
}
