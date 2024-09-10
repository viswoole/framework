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

/**
 * SwooleServer事件hook
 */
class ServerEventHook
{
  /**
   * @var array<string,callable[]> 事件处理
   */
  protected static array $handles = [];

  /**
   * 批量添加事件处理
   *
   * @param array<string,callable> $events 监听的事件
   * @return void
   */
  public static function addEvents(array $events): void
  {
    $events = array_change_key_case($events);
    foreach ($events as $event => $callback) {
      self::addEvent($event, $callback);
    }
  }

  /**
   * 添加事件处理
   *
   * @param string $event 事件名称
   * @param callable $callback 回调
   * @return void
   */
  public static function addEvent(string $event, callable $callback): void
  {
    self::$handles[strtolower(trim($event))][] = $callback;
  }

  /**
   * 获取需要hook的事件列表，用于注册到swoole服务中(该方法在由Server自动调用)
   *
   * @return array<string,callable> 事件列表
   */
  public static function getEventHooks(): array
  {
    $events = [];
    foreach (array_keys(self::$handles) as $event) {
      $events[$event] = function (mixed ...$args) use ($event) {
        self::dispatch($event, $args);
      };
    }
    return $events;
  }

  /**
   * 事件调度
   *
   * @param string $event
   * @param array $args
   * @return void
   */
  private static function dispatch(string $event, array $args): void
  {
    $handlers = self::$handles[$event] ?? null;
    if (!is_null($handlers)) {
      foreach ($handlers as $handler) {
        // 利用协程进行并发处理
        go(function () use ($handler, $args) {
          call_user_func_array($handler, $args);
        });
      }
    }
  }
}
