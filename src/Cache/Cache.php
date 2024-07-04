<?php
/*
 *  +----------------------------------------------------------------------
 *  | ViSwoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Cache;

use Closure;
use DateTime;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Core\Config;

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
 */
class Cache
{
  /**
   * @var string 默认缓存商店
   */
  protected string $defaultStore;
  /**
   * @var array<string,CacheDriverInterface> 缓存商店列表
   */
  protected array $stores;

  public function __construct(protected Config $config)
  {
    $stores = $config->get('cache.stores', []);
    $this->stores = $stores;
    if (!empty($this->stores)) {
      $this->defaultStore = $config->get('cache.default', array_keys($stores)[0]);
      foreach ($this->stores as $key => $driver) {
        if (!$driver instanceof CacheDriverInterface) {
          throw new CacheErrorException(
            $key . '缓存驱动配置错误，驱动类需实现' . CacheDriverInterface::class . '接口'
          );
        }
      }
    }
  }

  /**
   * 判断是否存在该缓存商店
   *
   * @access public
   * @param string $name
   * @return bool
   */
  public function hasStore(string $name): bool
  {
    return isset($this->stores[$name]);
  }

  /**
   * 转发调用
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public function __call(string $name, array $arguments)
  {
    return call_user_func_array([$this->store(), $name], $arguments);
  }

  /**
   * 指定缓存驱动
   *
   * @access public
   * @param string|null $name
   * @return CacheDriverInterface
   */
  public function store(string $name = null): CacheDriverInterface
  {
    if (empty($this->stores)) throw new CacheErrorException(
      '缓存商店为空，请先配置缓存商店'
    );
    if (is_null($name)) $name = $this->defaultStore;
    if (isset($this->stores[$name])) return $this->stores[$name];
    throw new CacheErrorException("缓存商店{$name}不存在");
  }
}
