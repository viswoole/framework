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
 * 日志收集器接口
 */
interface CollectorInterface
{
  /**
   * 程序运行错误时应抛出的日志。
   *
   * @param string|Stringable $message
   * @param array $context
   *
   * @return void
   */
  public function error(string|Stringable $message, array $context = []): void;

  /**
   * 警告日志，例如调用了弃用的API
   *
   * @param string|Stringable $message
   * @param array $context
   * @return void
   */
  public function warning(string|Stringable $message, array $context = []): void;

  /**
   * 普通的信息日志例如登录，注册等日志。
   *
   * @param string|Stringable $message
   * @param array $context
   * @return void
   */
  public function info(string|Stringable $message, array $context = []): void;

  /**
   * 详细的调试日志
   *
   * @param string|Stringable $message
   * @param array $context
   * @return void
   */
  public function debug(string|Stringable $message, array $context = []): void;

  /**
   * 任意日志
   *
   * @param string $level 日志标签
   * @param string|Stringable $message
   * @param array $context
   * @return void
   */
  public function mixed(string $level, string|Stringable $message, array $context = []): void;

  /**
   * sql日志。
   *
   * @param string|Stringable $message 描述消息
   * @param array $context 上下文
   *
   * @return void
   */
  public function sql(Stringable|string $message, array $context = []): void;

  /**
   * 任务日志。
   *
   * @param string|Stringable $message 描述消息
   * @param array $context 上下文
   *
   * @return void
   */
  public function task(Stringable|string $message, array $context = []): void;

  /**
   * 必须立即采取行动。
   *
   * Example: 整个网站关闭，数据库不可用等。这应该触发短信提醒并唤醒您。
   *
   * @param string|Stringable $message
   * @param array $context
   *
   * @return void
   */
  public function alert(string|Stringable $message, array $context = []): void;
}
