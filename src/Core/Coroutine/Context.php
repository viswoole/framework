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
 * 协程上下文辅助操作类，可通过Viswoole\Coroutine::getContext()获取Swoole协程上下文
 */
class Context
{
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
   * 将上下文从指定协程容器拷贝到当前容器
   *
   * @param int $fromCoroutineId 要复制的协程容器id
   * @param array $keys 要复制的记录键
   */
  public static function copy(int $fromCoroutineId, array $keys = []): void
  {
    $from = Coroutine::getContext($fromCoroutineId);

    if ($from === null) throw new RuntimeException('协程上下文未找到，或已经销毁。');

    $current = Coroutine::getContext();

    $map = $keys ? array_intersect_key(
      $from->getArrayCopy(), array_flip($keys)
    ) : $from->getArrayCopy();

    $current->exchangeArray($map);
  }
}
