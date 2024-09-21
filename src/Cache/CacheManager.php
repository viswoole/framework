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

namespace Viswoole\Cache;

use Closure;
use DateTime;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Driver\Redis;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Core\Config;

/**
 * 缓存驱动管理器
 *
 * @method int|false inc(string $key, int $step = 1) 自增缓存（针对数值缓存）
 * @method mixed get(string $key, mixed $default = null) 获取缓存
 * @method bool unlock(string $id) 解锁
 * @method bool set(string $key, mixed $value, DateTime|int|null $expire = null, bool $NX = false) 设置缓存
 * @method int|false ttl(string $key) 获取缓存剩余有效期 -1为长期有效 false为不存在或过期
 * @method int|false dec(string $key, int $step = 1) 自减缓存
 * @method mixed pull(string $key) 获取缓存并删除
 * @method int|false delete(array|string $keys) 删除缓存
 * @method bool has(string $key) 判断缓存是否存在
 * @method bool clear() 清除所有缓存
 * @method string lock(string $scene, int $expire = 10, bool $autoUnlock = false, int $retry = 5, int|float $sleep = 0.2) 获取竞争锁
 * @method void close() 关闭连接句柄（如果不手动调用则会在实例销毁时自动调用）
 * @method File connect() 获取连接句柄
 * @method int|false sAddArray(string $key, array|string $values) 往数组集合中追加值
 * @method array|false getArray(string $key) 获取数组集合
 * @method int|false sRemoveArray(string $key, array|string $values) 删除数组集合中的值
 * @method CacheDriverInterface setSerialize(Closure|string $set = 'serialize', Closure|string $get = 'unserialize') 设置序列化方法
 * @method string getTagKey(string $tag) 获取标签key
 * @method CacheTagInterface tag(array|string $tag) 标签
 * @method array|false getTags() 获取所有缓存标签
 * @method string getTagStoreName() 获取标签仓库名称
 * @method string getCacheKey(string $key) 获取实际的缓存标识
 */
class CacheManager
{
  public const string FILE_DRIVER = File::class;
  public const string REDIS_DRIVER = Redis::class;
  /**
   * @var string 默认缓存商店
   */
  protected string $defaultStore;
  /**
   * @var array<string,CacheDriverInterface> 缓存商店列表
   */
  protected array $stores;

  /**
   * @param Config $config
   */
  public function __construct(protected Config $config)
  {
    $stores = $config->get('cache.stores', []);
    $this->stores = $stores;
    if (!empty($this->stores)) {
      $this->defaultStore = $config->get('cache.default', array_key_first($stores));
      foreach ($this->stores as $key => $driver) $this->addStore($key, $driver);
    }
  }

  /**
   * 添加缓存商店
   *
   * @param string $name
   * @param CacheDriverInterface|string|array{driver:string,options:array{string:mixed}|object} $driver
   * @return void
   */
  public function addStore(string $name, CacheDriverInterface|string|array $driver): void
  {
    if (is_string($driver) && class_exists($driver)) $driver = new $driver();
    if (is_array($driver)) {
      if (!isset($driver['driver']) || !class_exists($driver['driver'])) {
        throw new CacheErrorException(
          $name . '缓存驱动配置错误，驱动类不存在'
        );
      }
      $options = $driver['options'] ?? [];
      $driver = new $driver['driver'](...$options);
    }
    if (!$driver instanceof CacheDriverInterface) {
      throw new CacheErrorException(
        $name . '缓存驱动配置错误，驱动类需实现' . CacheDriverInterface::class . '接口'
      );
    }
    $this->stores[$name] = $driver;
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
