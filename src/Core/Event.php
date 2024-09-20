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

namespace Viswoole\Core;

use InvalidArgumentException;
use ReflectionClass;

/**
 * 事件管理器
 */
class Event
{
  /**
   * @var array 监听器
   */
  private array $listens = [];

  /**
   * 触发事件
   *
   * 示例：
   * ```
   * use \Viswoole\Core\Facade\Event;
   * // 触发事件
   * Event::emit('userLogin', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * // 如果注册的监听器是一个类，则需要用"事件名称.方法名"触发
   * Event::emit('user.login', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * ```
   * @param string $event 事件名称
   * @param array $arguments 需要额外传递的参数
   * @return void
   */
  public function emit(string $event, array $arguments = []): void
  {
    $event = strtolower(trim($event));
    if (str_contains($event, '.')) {
      [$event, $id] = explode($event, '.', 2);
      $this->callHandle($event, $id, $arguments);
    } else {
      $listens = $this->listens[$event] ?? [];
      foreach (array_keys($listens) as $id) {
        $this->callHandle($event, $id, $arguments);
      }
    }
  }

  /**
   * 调用监听器
   *
   * @param string $event
   * @param string $id
   * @param array $arguments
   * @return void
   */
  private function callHandle(string $event, string $id, array $arguments): void
  {
    if (isset($this->listens[$event][$id])) {
      $listen = $this->listens[$event][$id];
      if ($listen['limit'] === 0 || $listen['count'] < $listen['limit']) {
        invoke($listen['handle'], $arguments);
        $this->listens[$event][$id]['count'] += 1;
        if ($this->listens[$event][$id]['count'] >= $listen['limit'] && $listen['limit'] !== 0) {
          $this->off($event, $id);
        }
      }
    }
  }

  /**
   * 关闭监听
   *
   * @param string $event 事件名称,不区分大小写
   * @param string|null $id 监听器id，如果为null，则关闭该事件的所有监听器
   * @return void
   */
  public function off(string $event, string $id = null): void
  {
    if (isset($this->listens[$event])) {
      if (is_null($id)) {
        unset($this->listens[$event]);
      } else {
        unset($this->listens[$event][$id]);
      }
    }
  }

  /**
   * 获取已监听的事件
   *
   * @return array
   */
  public function getEvents(): array
  {
    return array_keys($this->listens);
  }

  /**
   * 注册事件监听
   *
   * 示例：
   * ```
   * use \Viswoole\Core\Facade\Event;
   * // 模拟监听用户登录事件
   * $id = Event::on('userLogin', function(array $data){
   *    dump($data,'登录信息'); // ['id'=>1,'login_at'=>'2024-01-01 01:21:32']
   * })
   * // 触发用户登录事件
   * Event::emit('userLogin', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * // 清除监听器
   * Event::off('userLogin',$id);
   *
   * // 监听器类
   * class UserEvents{
   *   public static function login(array $data){
   *      dump($data,'登录信息'); // ['id'=>1,'login_at'=>'2024-01-01 01:21:32']
   *   }
   * }
   * // $handle传入监听器类，批量注册
   * Event::on('user', UserEvents::class);
   * // 触发监听器中的login方法
   * Event::emit('user.login', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']])
   * // 关闭监听器
   * Event::off('user.login');
   * ```
   *
   * @param string $event 不区分大小写的事件名称，不能包含`.`
   * @param callable|string $handle 任意可调用的回调，也可以是类名，如：UserEvents::class
   * @param int $limit 监听次数，0为不限制。
   * @return string|array 返回事件监听器id，仅用于删除监听器
   */
  public function on(string $event, callable|string $handle, int $limit = 0): string|array
  {
    if (str_contains($event, '.')) {
      throw new InvalidArgumentException('事件名称不能包含"."');
    }
    if (is_callable($handle)) {
      $event = strtolower(trim($event));
      $id = md5(uniqid($event . '_' . microtime(true), true));
      $this->listens[$event][$id] = [
        'limit' => $limit,
        'count' => 0,
        'handle' => $handle
      ];
      return $id;
    } elseif (class_exists($event)) {
      $eventList = [];
      $refClass = new ReflectionClass($event);
      $event = strtolower($refClass->getShortName());
      // 获取类的方法
      $methods = $refClass->getMethods();
      $this->events[] = $event;
      foreach ($methods as $method) {
        if (
          !$method->isPublic()
          || $method->isAbstract()
          || $method->isConstructor()
          || $method->isDestructor()
        ) continue;
        if ($method->isStatic()) {
          $handle = $refClass->getName() . '::' . $method->getName();
        } else {
          $handle = [$handle, $method->getName()];
        }
        $id = strtolower($method->getName());
        $eventList[] = $id;
        $this->listens[$event][$id] = [
          'limit' => $limit,
          'count' => 0,
          'handle' => $handle
        ];
      }
      return $eventList;
    } else {
      throw new InvalidArgumentException('$handle参数必须是任意可调用回调或完整类名称');
    }
  }

  /**
   * 清除所有事件监听
   *
   * @access public
   * @return void
   */
  public function offAll(): void
  {
    $this->listens = [];
  }
}
