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

declare(strict_types=1);

namespace Viswoole\Core;

use InvalidArgumentException;
use ReflectionClass;

/**
 * 事件管理器
 *
 * 提供事件的注册、触发和移除机制，支持闭包监听、类方法监听和 event.id 语法。
 * 事件名统一转为小写，确保 on/emit/off 大小写不敏感。
 * 支持监听次数限制，达到限制后自动移除。
 */
class Event
{
  /**
   * @var array<string,array<string,array{limit:int,count:int,handle:mixed}>> 已注册的监听器，键为事件名，值为监听器ID到配置的映射
   */
  private array $listens = [];

  /**
   * 触发事件，支持 "事件名.监听器ID" 语法定向触发指定监听器
   *
   * 示例：
   * ```
   * use \Viswoole\Core\Facade\Event;
   * // 触发事件的所有监听器
   * Event::emit('userLogin', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * // 仅触发 user 事件下 login 监听器
   * Event::emit('user.login', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * ```
   *
   * @param string $event 事件名称，不区分大小写
   * @param array $arguments 传递给监听器的参数
   */
  public function emit(string $event, array $arguments = []): void
  {
    $event = strtolower(trim($event));
    if (str_contains($event, '.')) {
      // 修复: explode 参数顺序应为 (分隔符, 字符串, 限制)
      [$event, $id] = explode('.', $event, 2);
      $this->callHandle($event, $id, $arguments);
    } else {
      $listens = $this->listens[$event] ?? [];
      foreach (array_keys($listens) as $id) {
        $this->callHandle($event, $id, $arguments);
      }
    }
  }

  /**
   * 调用指定监听器，受监听次数限制约束，达到限制后自动移除
   *
   * @param string $event 事件名（已小写化）
   * @param string $id 监听器ID
   * @param array $arguments 传递给监听器的参数
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
   * 移除事件监听器，不传 ID 时移除该事件的全部监听器
   *
   * @param string $event 事件名称，不区分大小写
   * @param string|null $id 监听器ID，为 null 时移除该事件的所有监听器
   */
  public function off(string $event, ?string $id = null): void
  {
    // 与 on/emit 保持一致，统一转换为小写
    $event = strtolower(trim($event));
    if (isset($this->listens[$event])) {
      if (is_null($id)) {
        unset($this->listens[$event]);
      } else {
        unset($this->listens[$event][$id]);
      }
    }
  }

  /**
   * 获取所有已注册监听的事件名列表
   *
   * @return array 事件名数组
   */
  public function getEvents(): array
  {
    return array_keys($this->listens);
  }

  /**
   * 注册事件监听器，支持闭包回调和类方法批量注册
   *
   * 闭包回调返回唯一监听器ID，类名注册则扫描所有 public 方法以方法名为ID批量注册
   *
   * 示例：
   * ```
   * use \Viswoole\Core\Facade\Event;
   * // 闭包监听
   * $id = Event::on('userLogin', function(array $data){
   *    dump($data,'登录信息');
   * })
   * Event::emit('userLogin', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * Event::off('userLogin', $id);
   *
   * // 类方法批量监听
   * class UserEvents{
   *   public static function login(array $data){ ... }
   * }
   * Event::on('user', UserEvents::class);
   * Event::emit('user.login', [['id'=>1,'login_at'=>'2024-01-01 01:21:32']]);
   * Event::off('user.login');
   * ```
   *
   * @param string $event 事件名称，不区分大小写，不能包含 '.'
   * @param callable|string $handle 闭包回调或监听器类名
   * @param int $limit 最大监听次数，0 为不限制，达到后自动移除
   * @return string|array 闭包注册返回监听器ID，类名注册返回方法名ID数组
   * @throws InvalidArgumentException 事件名包含 '.' 或 $handle 不可调用时抛出
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
    } elseif (class_exists($handle)) {
      // 修复: 应检查 $handle 而非 $event 是否为类名
      $eventList = [];
      $refClass = new ReflectionClass($handle);
      $event = strtolower(trim($event));
      $className = $refClass->getName();
      // 获取类的方法
      $methods = $refClass->getMethods();
      foreach ($methods as $method) {
        if (
          !$method->isPublic()
          || $method->isAbstract()
          || $method->isConstructor()
          || $method->isDestructor()
        ) continue;
        // 修复: 使用独立变量保存处理器，避免 $handle 被覆盖后影响后续循环
        if ($method->isStatic()) {
          $handler = $className . '::' . $method->getName();
        } else {
          $handler = [$className, $method->getName()];
        }
        $id = strtolower($method->getName());
        $eventList[] = $id;
        $this->listens[$event][$id] = [
          'limit' => $limit,
          'count' => 0,
          'handle' => $handler
        ];
      }
      return $eventList;
    } else {
      throw new InvalidArgumentException('$handle参数必须是任意可调用回调或完整类名称');
    }
  }

  /**
   * 清除所有事件监听器
   */
  public function offAll(): void
  {
    $this->listens = [];
  }
}
