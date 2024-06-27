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

namespace Viswoole\Core\Server;

use Closure;
use Swoole\Server as SwooleServer;

/**
 * SwooleServer事件hook
 */
class EventHandler
{
  /**
   * @var array
   */
  protected static array $hook = [];

  /**
   * HOOK监听服务启动
   *
   * @access public
   * @param SwooleServer $server
   * @return void
   */
  public static function start(SwooleServer $server): void
  {
    self::runHook(__FUNCTION__, func_get_args());
  }

  /**
   * 运行用户自定义的监听
   *
   * @param string $event
   * @param array $args
   * @return void
   */
  private static function runHook(string $event, array $args): void
  {
    $handler = self::$hook[$event] ?? null;
    if (!is_null($handler)) {
      if ($handler instanceof Closure) {
        $handler(...$args);
      } else {
        call_user_func_array($handler, $args);
      }
    }
  }

  /**
   * 服务正常关闭事件hook
   *
   * @access public
   * @param SwooleServer $server
   * @return void
   */
  public static function shutdown(SwooleServer $server): void
  {
    self::runHook(__FUNCTION__, func_get_args());
  }

  /**
   * 任务回调
   *
   * @access public
   * @return void
   */
  public static function finish(): void
  {
    self::runHook(__FUNCTION__, func_get_args());
  }

  /**
   * hook
   *
   * @param array $events 监听的事件
   * @return array
   */
  public static function hook(array $events): array
  {
    $events = array_change_key_case($events);
    foreach ($events as $event => $value) {
      if (method_exists(self::class, $event)) {
        self::$hook[$event] = $value;
        $events[$event] = [self::class, $event];
      }
    }
    return $events;
  }
}
