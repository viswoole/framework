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

use Closure;
use DateTime;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Monitor\TraceContext;

/**
 * 缓存驱动监控装饰器
 *
 * 职责：包装任意缓存驱动，将读写操作按商店名与键采集到当前请求的
 * 慢请求记录中。
 * 边界：保留驱动的返回语义与异常语义；仅采集键和结果摘要，不采集
 * 缓存值；是否采集由当前请求上下文决定（无上下文时零开销直通）。
 *
 * @see CacheDriverInterface 被装饰的驱动接口
 */
final class MonitoredDriver implements CacheDriverInterface
{
  /**
   * 构造装饰器
   *
   * @param CacheDriverInterface $driver 被包装的原始驱动
   * @param string $store 所属缓存商店名（小写），仅用于采集记录
   */
  public function __construct(private CacheDriverInterface $driver, private string $store) {}

  /**
   * 采集并执行驱动操作
   *
   * @param string $method 操作名
   * @param array $arguments 调用参数
   * @return mixed 驱动返回值
   */
  private function invoke(string $method, array $arguments): mixed
  {
    return TraceContext::measure([
      'type' => 'cache', 'store' => $this->store, 'operation' => $method,
      'key' => $arguments[0] ?? null,
    ], fn(): mixed => $this->driver->$method(...$arguments));
  }

  /**
   * 创建标签实例
   *
   * @param array|string $tag 标签名，支持批量
   * @return CacheTagInterface 标签操作装饰器
   */
  public function tag(array|string $tag): CacheTagInterface
  {
    // 基于原始驱动创建，避免标签内部操作被双重装饰
    return new MonitoredTag($tag, $this->driver, $this->store);
  }

  /**
   * 设置序列化方法
   *
   * @param Closure|string $set 序列化方法名或闭包
   * @param Closure|string $get 反序列化方法名或闭包
   * @return static 返回当前实例，支持链式调用
   */
  public function setSerialize(Closure|string $set = 'serialize', Closure|string $get = 'unserialize'): static
  {
    $this->driver->setSerialize($set, $get);
    return $this;
  }

  /**
   * 析构方法
   *
   * 仅满足 CacheDriverInterface 的析构契约，不做任何操作：驱动生命周期
   * 仍由原实例管理（CacheManager 常驻持有，随进程退出释放），装饰器析构
   * 若转发 close 会与驱动自身的析构释放重复执行，导致共享连接被提前关闭。
   */
  public function __destruct() {}

  /**
   * 自增缓存
   *
   * @param string $key 缓存键名
   * @param int $step 自增步长
   * @return false|int 自增后的值，失败返回 false
   */
  public function inc(string $key, int $step = 1): false|int
  {
    return $this->invoke('inc', [$key, $step]);
  }

  /**
   * 自减缓存
   *
   * @param string $key 缓存键名
   * @param int $step 自减步长
   * @return false|int 自减后的值，失败返回 false
   */
  public function dec(string $key, int $step = 1): false|int
  {
    return $this->invoke('dec', [$key, $step]);
  }

  /**
   * 设置缓存
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
   * 读取缓存
   *
   * @param string $key 缓存键名
   * @param mixed $default 键不存在时的默认值
   * @return mixed 缓存值（不采集）或默认值
   */
  public function get(string $key, mixed $default = null): mixed
  {
    return $this->invoke('get', [$key, $default]);
  }

  /**
   * 读取并删除缓存
   *
   * @param string $key 缓存键名
   * @return mixed 缓存值（不采集），不存在时返回 null
   */
  public function pull(string $key): mixed
  {
    return $this->invoke('pull', [$key]);
  }

  /**
   * 判断缓存是否存在
   *
   * @param string $key 缓存键名
   * @return bool 存在返回 true
   */
  public function has(string $key): bool
  {
    return $this->invoke('has', [$key]);
  }

  /**
   * 清除当前商店全部缓存
   *
   * @return bool 清除成功返回 true
   */
  public function clear(): bool
  {
    return $this->invoke('clear', []);
  }

  /**
   * 删除缓存
   *
   * @param array|string $keys 缓存键名，支持批量
   * @return false|int 成功删除的键数量，失败返回 false
   */
  public function delete(array|string $keys): false|int
  {
    return $this->invoke('delete', [$keys]);
  }

  /**
   * 获取竞争锁
   *
   * @param string $scene 锁场景名
   * @param int $expire 锁过期时间（秒）
   * @param bool $autoUnlock 是否自动解锁
   * @param int $retry 获取失败重试次数
   * @param int|float $sleep 重试间隔（秒）
   * @return string 锁唯一标识，用于 unlock
   */
  public function lock(string $scene, int $expire = 10, bool $autoUnlock = false, int $retry = 5, int|float $sleep = 0.2): string
  {
    return $this->invoke('lock', [$scene, $expire, $autoUnlock, $retry, $sleep]);
  }

  /**
   * 释放锁
   *
   * @param string $id lock 返回的锁唯一标识
   * @return bool 释放成功返回 true
   */
  public function unlock(string $id): bool
  {
    return $this->invoke('unlock', [$id]);
  }

  /**
   * 获取缓存剩余有效期
   *
   * @param string $key 缓存键名
   * @return false|int 剩余秒数，-1 为长期有效，false 为不存在或已过期
   */
  public function ttl(string $key): false|int
  {
    return $this->invoke('ttl', [$key]);
  }

  /**
   * 往数组集合中追加值
   *
   * @param string $key 集合键名
   * @param array|string $values 追加的值，支持批量
   * @return false|int 追加成功的数量，失败返回 false
   */
  public function sAddArray(string $key, array|string $values): false|int
  {
    return $this->invoke('sAddArray', [$key, $values]);
  }

  /**
   * 从数组集合中删除值
   *
   * @param string $key 集合键名
   * @param array|string $values 删除的值，支持批量
   * @return false|int 删除成功的数量，失败返回 false
   */
  public function sRemoveArray(string $key, array|string $values): false|int
  {
    return $this->invoke('sRemoveArray', [$key, $values]);
  }

  /**
   * 获取数组集合
   *
   * @param string $key 集合键名
   * @return array|false 集合内容（不采集），不存在时返回 false
   */
  public function getArray(string $key): array|false
  {
    return $this->invoke('getArray', [$key]);
  }

  /**
   * 获取所有缓存标签
   *
   * @return array|false 标签列表，失败返回 false
   */
  public function getTags(): array|false
  {
    return $this->invoke('getTags', []);
  }

  /**
   * 获取标签键
   *
   * 元数据查询不采集计时。
   *
   * @param string $tag 标签名
   * @return string 标签实际存储键名
   */
  public function getTagKey(string $tag): string
  {
    return $this->driver->getTagKey($tag);
  }

  /**
   * 获取标签仓库名称
   *
   * 元数据查询不采集计时。
   *
   * @return string 标签仓库名称
   */
  public function getTagStoreName(): string
  {
    return $this->driver->getTagStoreName();
  }

  /**
   * 获取实际的缓存标识
   *
   * 元数据查询不采集计时。
   *
   * @param string $key 缓存键名
   * @return string 加商店前缀后的实际键名
   */
  public function getCacheKey(string $key): string
  {
    return $this->driver->getCacheKey($key);
  }

  /**
   * 获取底层连接句柄
   *
   * @return mixed 连接句柄
   */
  public function connect(): mixed
  {
    return $this->driver->connect();
  }

  /**
   * 关闭连接句柄
   *
   * 显式调用时透传给被包装驱动；装饰器自身析构不触发关闭。
   */
  public function close(): void
  {
    $this->driver->close();
  }
}
