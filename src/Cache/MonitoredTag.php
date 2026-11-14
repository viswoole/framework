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

namespace Viswoole\Cache;

use DateTime;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Monitor\TraceContext;

/**
 * 缓存标签监控装饰器
 *
 * 职责：包装驱动创建的标签实例，将标签维度的读写操作按完整逻辑操作
 * （tag.set、tag.remove 等）采集到当前请求的慢请求记录中。
 * 边界：保留自定义驱动的标签实现；基于原始驱动创建，不会被双重装饰；
 * 不采集缓存值。
 *
 * @see CacheTagInterface 被装饰的标签接口
 */
final class MonitoredTag implements CacheTagInterface
{
  /** @var CacheTagInterface 被包装的原始标签实例 */
  private CacheTagInterface $tag;

  /**
   * 构造标签装饰器
   *
   * @param string|array $tags 标签名，支持批量
   * @param CacheDriverInterface $driver 原始缓存驱动（非装饰器）
   * @param string $store 所属缓存商店名，仅用于采集记录
   */
  public function __construct(private string|array $tags, CacheDriverInterface $driver, private string $store = '')
  {
    $this->tag = $driver->tag($tags);
  }

  /**
   * 采集并执行标签操作
   *
   * @param string $method 标签操作
   * @param array $arguments 调用参数
   * @return mixed 原标签结果
   */
  private function invoke(string $method, array $arguments = []): mixed
  {
    return TraceContext::measure([
      'type' => 'cache', 'store' => $this->store, 'operation' => 'tag.' . $method,
      'tags' => $this->tags, 'key' => $arguments[0] ?? null,
    ], fn(): mixed => $this->tag->$method(...$arguments));
  }

  /**
   * 设置标签关联的缓存
   *
   * @param string $key 缓存键名
   * @param mixed $value 缓存值（不采集）
   * @param DateTime|int|null $expire 过期时间，null 表示长期有效
   * @param bool $NX 是否仅在键不存在时写入
   * @return bool 设置成功返回 true
   */
  public function set(string $key, mixed $value, DateTime|int|null $expire = null, bool $NX = false): bool
  {
    return $this->invoke('set', [$key, $value, $expire, $NX]);
  }

  /**
   * 清除标签下的全部缓存
   */
  public function clear(): void
  {
    $this->invoke('clear');
  }

  /**
   * 删除标签下的指定缓存
   *
   * @param string|array $keys 缓存键名，支持批量
   */
  public function remove(string|array $keys): void
  {
    $this->invoke('remove', [$keys]);
  }

  /**
   * 将已有缓存键加入标签
   *
   * @param string $key 缓存键名
   */
  public function push(string $key): void
  {
    $this->invoke('push', [$key]);
  }

  /**
   * 获取标签下的全部缓存键
   *
   * @return array 缓存键列表
   */
  public function get(): array
  {
    return $this->invoke('get');
  }
}
