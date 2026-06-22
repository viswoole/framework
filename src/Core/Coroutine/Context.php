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

namespace Viswoole\Core\Coroutine;

use RuntimeException;
use Viswoole\Core\Coroutine;

/**
 * 协程上下文辅助操作类
 *
 * 封装 Swoole\Coroutine\Context 的读写操作，提供键值对式的上下文访问接口，
 * 支持跨协程拷贝和指定协程 ID 操作。
 */
class Context
{
  /**
   * 将上下文从指定协程拷贝到当前协程，可选择仅拷贝指定键或合并到已有上下文
   *
   * @param int $fromCoroutineId 源协程ID
   * @param array $keys 要拷贝的键名列表，为空则拷贝全部
   * @param bool $merge 是否与当前上下文合并，为 false 时覆盖当前上下文
   * @throws RuntimeException 源协程上下文不存在时抛出
   */
  public static function copy(int $fromCoroutineId, array $keys = [], bool $merge = false): void
  {
    $from = Coroutine::getContext($fromCoroutineId);
    if ($from === null) throw new RuntimeException('协程上下文未找到，或已经销毁。');
    $current = Coroutine::getContext();
    $map = empty($keys)
      ? $from->getArrayCopy()
      : array_intersect_key($from->getArrayCopy(), array_flip($keys));
    // 如果需要合并当前上下文的内容
    if ($merge) {
      $current->exchangeArray(array_merge($current->getArrayCopy(), $map));
    } else {
      $current->exchangeArray($map);
    }
  }

  /**
   * 判断协程上下文中是否存在指定键
   *
   * @param string $key 上下文键名
   * @param int $id 协程ID，0 为当前协程
   * @return bool 存在返回 true
   */
  public static function has(string $key, int $id = 0): bool
  {
    return isset(Coroutine::getContext($id)[$key]);
  }

  /**
   * 从协程上下文中获取值
   *
   * @param string $key 上下文键名
   * @param mixed|null $default 键不存在时的默认值
   * @param int $id 协程ID，0 为当前协程
   * @return mixed 键对应的值或默认值
   */
  public static function get(string $key, mixed $default = null, int $id = 0): mixed
  {
    return Coroutine::getContext($id)[$key] ?? $default;
  }

  /**
   * 向协程上下文中写入键值对
   *
   * @param string $key 上下文键名
   * @param mixed $value 要存储的值
   * @param int $id 协程ID，0 为当前协程
   */
  public static function set(string $key, mixed $value, int $id = 0): void
  {
    Coroutine::getContext($id)[$key] = $value;
  }

  /**
   * 从协程上下文中移除指定键
   *
   * @param string $key 上下文键名
   * @param int $id 协程ID，0 为当前协程
   */
  public static function remove(string $key, int $id = 0): void
  {
    unset(Coroutine::getContext($id)[$key]);
  }

  /**
   * 获取完整的协程上下文对象
   *
   * @param int $id 协程ID，0 为当前协程
   * @return \Swoole\Coroutine\Context|null 协程上下文对象
   */
  public function all(int $id = 0): ?\Swoole\Coroutine\Context
  {
    return Coroutine::getContext($id);
  }

}
