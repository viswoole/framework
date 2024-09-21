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
 */
class Context
{
  /**
   * 将上下文从指定协程容器拷贝到当前容器
   *
   * @param int $fromCoroutineId 要复制的协程容器id
   * @param array $keys 要复制的记录键,为空则复制所有上下文
   * @param bool $merge 是否合并到当前上下文，默认为false，即不保留当前协程上下文
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
   * 判断属性协程上下文中是否存在
   * @param string $key
   * @param int $id 协程id，默认为当前协程
   * @return bool
   */
  public static function has(string $key, int $id = 0): bool
  {
    return isset(Coroutine::getContext($id)[$key]);
  }

  /**
   * 从协程上下文中获取记录
   *
   * @param string $key
   * @param mixed|null $default
   * @param int $id 协程id，默认为当前协程
   * @return false|mixed|null
   */
  public static function get(string $key, mixed $default = null, int $id = 0): mixed
  {
    return Coroutine::getContext($id)[$key] ?? $default;
  }

  /**
   * 往协程上下文中新增记录
   *
   * @param string $key
   * @param mixed $value
   * @param int $id 协程id，默认为当前协程
   * @return void
   */
  public static function set(string $key, mixed $value, int $id = 0): void
  {
    Coroutine::getContext($id)[$key] = $value;
  }

  /**
   * 删除上下文
   *
   * @param string $key 上下文记录键
   * @param int $id 协程id，默认为当前协程
   * @return void
   */
  public static function remove(string $key, int $id = 0): void
  {
    unset(Coroutine::getContext($id)[$key]);
  }

  /**
   * 获取完整的上下文
   *
   * @param int $id
   * @return \Swoole\Coroutine\Context|null
   */
  public function all(int $id = 0): ?\Swoole\Coroutine\Context
  {
    return Coroutine::getContext($id);
  }

}
