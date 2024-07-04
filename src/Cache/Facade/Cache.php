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

namespace Viswoole\Cache\Facade;

use Closure;
use DateTime;
use Override;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Driver\File;
use Viswoole\Core\Facade;

/**
 * 缓存驱动管理器
 *
 * @method static int|false inc(string $key, int $step = 1) 自增缓存（针对数值缓存）
 * @method static mixed get(string $key, mixed $default = null) 获取缓存
 * @method static bool unlock(string $id) 解锁
 * @method static bool set(string $key, mixed $value, DateTime|int|null $expire = null, bool $NX = false) 设置缓存
 * @method static int|false ttl(string $key) 获取缓存剩余有效期 -1为长期有效 false为不存在或过期
 * @method static int|false dec(string $key, int $step = 1) 自减缓存
 * @method static mixed pull(string $key) 获取缓存并删除
 * @method static int|false delete(array|string $keys) 删除缓存
 * @method static bool has(string $key) 判断缓存是否存在
 * @method static bool clear() 清除所有缓存
 * @method static string lock(string $scene, int $expire = 10, bool $autoUnlock = false, int $retry = 5, int|float $sleep = 0.2) 获取竞争锁
 * @method static void close() 关闭连接句柄（如果不手动调用则会在实例销毁时自动调用）
 * @method static File connect() 获取连接句柄
 * @method static int|false sAddArray(string $key, array|string $values) 往数组集合中追加值
 * @method static array|false getArray(string $key) 获取数组集合
 * @method static int|false sRemoveArray(string $key, array|string $values) 删除数组集合中的值
 * @method static CacheDriverInterface setSerialize(Closure|string $set = 'serialize', Closure|string $get = 'unserialize') 设置序列化方法
 * @method static string getTagKey(string $tag) 获取标签key
 * @method static CacheTagInterface tag(array|string $tag) 标签
 * @method static array|false getTags() 获取所有缓存标签
 * @method static string getTagStoreName() 获取标签仓库名称
 * @method static string getCacheKey(string $key) 获取实际的缓存标识
 * @method static bool hasStore(string $name) 判断是否存在该缓存商店
 * @method static CacheDriverInterface store(string $name = null) 指定缓存驱动
 * @method static void addStore(string $name, CacheDriverInterface $driver) 判断是否存在该缓存商店
 */
class Cache extends Facade
{
  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Cache\Cache::class;
  }
}
