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

/**
 * 缓存驱动接口
 */
interface CacheDriverInterface
{
  /**
   * 自增缓存（针对数值缓存）
   *
   * @access public
   * @param string $key 缓存标识
   * @param int $step 步长
   * @return false|int 失败返回false成功返回记录值
   */
  public function inc(string $key, int $step = 1): false|int;

  /**
   * 获取标签仓库名称
   *
   * @return string
   */
  public function getTagStoreName(): string;

  /**
   * 自减缓存（针对数值缓存）
   *
   * @access public
   * @param string $key 缓存标识
   * @param int $step 步长
   * @return false|int 失败返回false成功返回记录值
   */
  public function dec(string $key, int $step = 1): false|int;

  /**
   * 写入缓存
   *
   * @access public
   * @param string $key 缓存标识
   * @param mixed $value 存储数据
   * @param DateTime|int $expire 有效时间（秒）
   * @param bool $NX 如果为true则缓存不存在才会写入
   * @return bool
   */
  public function set(
    string       $key,
    mixed        $value,
    DateTime|int $expire = 0,
    bool         $NX = false
  ): bool;

  /**
   * 读取缓存并删除
   * @access public
   * @param string $key 缓存标识
   * @return mixed
   */
  public function pull(string $key): mixed;

  /**
   * 判断缓存
   *
   * @access public
   * @param string $key 缓存标识
   * @return bool
   */
  public function has(string $key): bool;

  /**
   * 读取缓存
   *
   * @access public
   * @param string $key 不带前缀的名称
   * @param mixed $default 默认值
   * @return mixed 如果$key不存在则返回$default默认值
   */
  public function get(string $key, mixed $default = null): mixed;

  /**
   * 清除缓存
   *
   * @access public
   * @return bool
   */
  public function clear(): bool;

  /**
   * 删除缓存
   *
   * @access public
   * @param array|string $keys
   * @return false|int
   */
  public function delete(array|string $keys): false|int;

  /**
   * 取锁/上锁
   *
   * @access public
   * @param string $scene 业务场景
   * @param int $expire 锁过期时间/秒
   * @param bool $autoUnlock 在程序运行完毕后自动解锁默认false
   * @param int $retry 等待尝试次数
   * @param int|float $sleep 等待休眠时间/秒 最小精度为毫秒（0.001 秒）
   * @return string 成功返回锁id 失败抛出系统繁忙错误
   */
  public function lock(
    string    $scene,
    int       $expire = 10,
    bool      $autoUnlock = false,
    int       $retry = 5,
    int|float $sleep = 0.2
  ): string;

  /**
   * 解除锁
   *
   * @access public
   * @param string $id 通过lock方法返回的锁ID
   * @return bool 解锁成功返回true，否则返回false
   */
  public function unlock(string $id): bool;

  /**
   * 关闭连接(实例销毁会自动关闭连接/归还连接到连接池/自动解除悲观锁)
   *
   * 当实例对象销毁时__destruct析构方法会自动调用close方法进行善后
   *
   * @return void
   */
  public function close(): void;

  /**
   * 获取剩余生存时间
   *
   * @param string $key
   * @return false|int 如果key没有ttl，-1则返回，如果key不存在false则返回
   */
  public function ttl(string $key): false|int;

  /**
   * 实现析构方法，在对象销毁时自动关闭连接
   */
  public function __destruct();

  /**
   * 标签
   *
   * @access public
   * @param string|array $tag
   * @return CacheTagInterface
   */
  public function tag(string|array $tag): CacheTagInterface;

  /**
   * 获取所有缓存标签
   *
   * @access public
   * @return array|false
   */
  public function getTags(): array|false;

  /**
   * 获取实际标签名
   *
   * @access public
   * @param string $tag 标签名
   * @return string
   */
  public function getTagKey(string $tag): string;

  /**
   * 获取连接实例(例如redis驱动获取redis实例实现更多的操作)
   *
   * @return mixed
   */
  public function connect(): mixed;

  /**
   * 获取实际的缓存标识
   *
   * @access public
   * @param string $key 缓存标识
   * @return string
   */
  public function getCacheKey(string $key): string;

  /**
   * 往数组集合中添加记录
   *
   * @access public
   * @param string $key 缓存名称
   * @param array|string $values 记录值
   * @return false|int 如果写入的值已存在则会返回false，其他返回写入的数量
   */
  public function sAddArray(string $key, array|string $values): false|int;

  /**
   * 获取数组集合
   *
   * @access public
   * @param string $key 集合名称
   * @return array|false
   */
  public function getArray(string $key): array|false;

  /**
   * 从集合中移除元素
   *
   * @access public
   * @param string $key 集合名称
   * @param array|string $values 要删除的值
   * @return false|int
   */
  public function sRemoveArray(
    string       $key,
    array|string $values
  ): false|int;

  /**
   * 设置序列化方法
   *
   * @access public
   * @param string|Closure $set
   * @param string|Closure $get
   * @return static
   */
  public function setSerialize(
    string|Closure $set = 'serialize',
    string|Closure $get = 'unserialize'
  ): static;
}
