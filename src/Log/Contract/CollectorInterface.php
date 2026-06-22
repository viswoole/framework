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

namespace Viswoole\Log\Contract;

use Stringable;

/**
 * 日志收集器接口，定义各级别日志记录的统一契约
 *
 * 遵循 PSR-3 日志等级语义，提供 error、warning、info、debug、sql、task、alert
 * 及自定义级别 mixed 的方法签名。
 */
interface CollectorInterface
{
  /**
   * 记录运行时错误（无需立即处理但需监控）
   *
   * @param string|Stringable $message 错误描述
   * @param array $context 附加上下文
   */
  public function error(string|Stringable $message, array $context = []): void;

  /**
   * 记录非错误的异常情况，如调用弃用 API
   *
   * @param string|Stringable $message 警告描述
   * @param array $context 附加上下文
   */
  public function warning(string|Stringable $message, array $context = []): void;

  /**
   * 记录普通业务信息，如用户登录、注册等
   *
   * @param string|Stringable $message 信息描述
   * @param array $context 附加上下文
   */
  public function info(string|Stringable $message, array $context = []): void;

  /**
   * 记录详细调试信息，仅在开发调试阶段使用
   *
   * @param string|Stringable $message 调试描述
   * @param array $context 附加上下文
   */
  public function debug(string|Stringable $message, array $context = []): void;

  /**
   * 记录自定义级别的日志
   *
   * @param string $level 自定义日志级别标签
   * @param string|Stringable $message 日志消息
   * @param array $context 附加上下文
   */
  public function mixed(string $level, string|Stringable $message, array $context = []): void;

  /**
   * 记录 SQL 执行日志
   *
   * @param string|Stringable $message SQL 语句或描述
   * @param array $context 附加上下文
   */
  public function sql(Stringable|string $message, array $context = []): void;

  /**
   * 记录异步任务日志
   *
   * @param string|Stringable $message 任务描述
   * @param array $context 附加上下文
   */
  public function task(Stringable|string $message, array $context = []): void;

  /**
   * 记录必须立即采取行动的警报，如整个网站关闭、数据库不可用
   *
   * 此级别应触发短信提醒等紧急通知。
   *
   * @param string|Stringable $message 警报描述
   * @param array $context 附加上下文
   */
  public function alert(string|Stringable $message, array $context = []): void;
}
