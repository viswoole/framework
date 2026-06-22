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

namespace Viswoole\Cache\Contract;

use Closure;
use DateTime;
use Viswoole\Cache\Exception\CacheErrorException;

/**
 * 缓存驱动接口，定义所有缓存驱动必须实现的核心操作契约
 *
 * 涵盖键值读写、自增自减、竞争锁、标签管理、数组集合及序列化等能力，
 * File 和 Redis 驱动均实现此接口。
 */
interface CacheDriverInterface
{
  /**
   * 对数值缓存执行自增操作
   *
   * @param string $key 缓存标识
   * @param int $step 自增步长，默认为 1
   * @return false|int 操作失败返回 false，成功返回自增后的值
   */
  public function inc(string $key, int $step = 1): false|int;

  /**
   * 获取标签仓库的完整缓存键名（含前缀）
   *
   * @return string 标签仓库键名
   */
  public function getTagStoreName(): string;

  /**
   * 对数值缓存执行自减操作
   *
   * @param string $key 缓存标识
   * @param int $step 自减步长，默认为 1
   * @return false|int 操作失败返回 false，成功返回自减后的值
   */
  public function dec(string $key, int $step = 1): false|int;

  /**
   * 写入缓存，支持过期时间与 NX（仅不存在时写入）模式
   *
   * @param string $key 缓存标识
   * @param mixed $value 存储数据
   * @param DateTime|int|null $expire 过期时间（秒），null 使用驱动默认值
   * @param bool $NX 是否仅在缓存不存在时写入
   * @return bool 写入成功返回 true
   */
  public function set(
    string            $key,
    mixed             $value,
    DateTime|int|null $expire = null,
    bool              $NX = false
  ): bool;

  /**
   * 读取缓存值并立即删除该缓存
   *
   * @param string $key 缓存标识
   * @return mixed 缓存值，不存在时返回 null
   */
  public function pull(string $key): mixed;

  /**
   * 判断缓存键是否存在
   *
   * @param string $key 缓存标识
   * @return bool 存在返回 true
   */
  public function has(string $key): bool;

  /**
   * 读取缓存值，不存在时返回默认值
   *
   * @param string $key 缓存标识（不含前缀）
   * @param mixed $default 缓存不存在时的默认返回值
   * @return mixed 缓存值或默认值
   */
  public function get(string $key, mixed $default = null): mixed;

  /**
   * 清除当前驱动下的所有缓存数据
   *
   * @return bool 清除成功返回 true
   */
  public function clear(): bool;

  /**
   * 删除一个或多个缓存键
   *
   * @param array|string $keys 缓存键或键列表
   * @return false|int 删除失败返回 false，成功返回删除的数量
   */
  public function delete(array|string $keys): false|int;

  /**
   * 获取竞争锁，支持重试等待与自动解锁
   *
   * @param string $scene 业务场景标识
   * @param int $expire 锁过期时间（秒）
   * @param bool $autoUnlock 是否在连接关闭时自动解锁
   * @param int $retry 获取锁失败时的重试次数
   * @param int|float $sleep 每次重试间隔（秒），最小精度毫秒
   * @return string 锁 ID，用于后续解锁验证
   * @throws CacheErrorException 重试耗尽仍未获取到锁时抛出
   */
  public function lock(
    string    $scene,
    int       $expire = 10,
    bool      $autoUnlock = false,
    int       $retry = 5,
    int|float $sleep = 0.2
  ): string;

  /**
   * 释放竞争锁，仅当锁 ID 匹配时才能解锁
   *
   * @param string $id 由 lock 方法返回的锁 ID
   * @return bool 解锁成功返回 true，锁 ID 不匹配或锁不存在返回 false
   */
  public function unlock(string $id): bool;

  /**
   * 关闭连接句柄，归还连接到连接池并释放自动解锁的锁
   *
   * 对象析构时会自动调用此方法，也可手动调用提前释放资源。
   */
  public function close(): void;

  /**
   * 获取缓存键的剩余生存时间
   *
   * @param string $key 缓存标识
   * @return false|int 键不存在或已过期返回 false，永不过期返回 -1，否则返回剩余秒数
   */
  public function ttl(string $key): false|int;

  /**
   * 析构方法，对象销毁时自动调用 close 释放资源
   */
  public function __destruct();

  /**
   * 创建缓存标签实例，用于按标签分组管理缓存
   *
   * @param string|array $tag 一个或多个标签名
   * @return CacheTagInterface 标签操作实例
   */
  public function tag(string|array $tag): CacheTagInterface;

  /**
   * 获取所有已注册的缓存标签集合
   *
   * @return array|false 标签列表，标签仓库不存在时返回 false
   */
  public function getTags(): array|false;

  /**
   * 根据标签名生成带前缀的标签键
   *
   * @param string $tag 原始标签名
   * @return string 带前缀的标签键
   */
  public function getTagKey(string $tag): string;

  /**
   * 获取底层连接实例，用于执行驱动未封装的原生操作
   *
   * @return mixed 底层连接实例（如 \Redis、File 等）
   */
  public function connect(): mixed;

  /**
   * 根据缓存键生成带前缀的完整缓存标识
   *
   * @param string $key 原始缓存键
   * @return string 含前缀的完整缓存键
   */
  public function getCacheKey(string $key): string;

  /**
   * 向数组集合中追加一个或多个值，已存在的值不会重复添加
   *
   * @param string $key 集合的缓存键
   * @param array|string $values 待追加的值或值列表
   * @return false|int 所有值已存在返回 false，否则返回新增的数量
   */
  public function sAddArray(string $key, array|string $values): false|int;

  /**
   * 获取数组集合的所有成员
   *
   * @param string $key 集合的缓存键
   * @return array|false 集合成员列表，集合不存在时返回 false
   */
  public function getArray(string $key): array|false;

  /**
   * 从数组集合中移除一个或多个值
   *
   * @param string $key 集合的缓存键
   * @param array|string $values 待移除的值或值列表
   * @return false|int 移除失败返回 false，成功返回移除的数量
   */
  public function sRemoveArray(
    string       $key,
    array|string $values
  ): false|int;

  /**
   * 自定义序列化与反序列化回调
   *
   * @param string|Closure $set 序列化回调，将值转为可存储的字符串
   * @param string|Closure $get 反序列化回调，将存储的字符串还原为原始值
   * @return static 支持链式调用
   */
  public function setSerialize(
    string|Closure $set = 'serialize',
    string|Closure $get = 'unserialize'
  ): static;
}
