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

use Override;
use Stringable;
use Viswoole\Core\Facade;
use Viswoole\Log\Contract\DriveInterface;
use Viswoole\Log\LogManager;

/**
 * 日志门面，提供静态代理访问 LogManager 的所有方法
 *
 * @method static void mixed(string $level, string|Stringable $message, array $context = []) 记录自定义级别的日志
 * @method static void alert(string|Stringable $message, array $context = []) 记录必须立即采取行动的警报
 * @method static void error(string|Stringable $message, array $context = []) 记录运行时错误
 * @method static void warning(string|Stringable $message, array $context = []) 记录非错误的异常情况
 * @method static void info(string|Stringable $message, array $context = []) 记录普通业务信息
 * @method static void debug(string|Stringable $message, array $context = []) 记录详细调试信息
 * @method static void sql(string|Stringable $message, array $context = []) 记录SQL执行日志
 * @method static void task(string|Stringable $message, array $context = []) 记录异步任务日志
 * @method static void write(string $level, Stringable|string $message, array $context = []) 绕过缓存直接写入日志
 * @method static void record(string $level, Stringable|string $message, array $context = []) 缓存日志，协程结束时批量写入
 * @method static bool save(array $logRecords) 批量保存日志（协程结束时自动调用）
 * @method static bool clearRecord() 清除当前协程缓存的日志
 * @method static array getRecord() 获取当前协程缓存的日志
 * @method static DriveInterface channel(string $name) 获取指定通道的驱动实例
 * @method static bool hasChannel(string $name) 判断指定通道是否已注册
 * @method static void addChannel(string $name, DriveInterface|string|array $channel) 注册一个日志通道
 *
 * @see LogManager 日志管理器
 */
class Log extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return LogManager::class;
  }
}
