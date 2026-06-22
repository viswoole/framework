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
 * 缓存商店管理器，负责多驱动商店的注册、切换与方法代理转发
 *
 * 通过统一的代理接口（__call）将调用转发至默认商店或指定商店的驱动实例，
 * 实现多缓存存储的透明切换。商店名称不区分大小写，内部统一转为小写索引。
 *
 * @see CacheDriverInterface 被代理的缓存驱动接口
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
 * @method File|mixed connect() 获取连接句柄
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
   * @var string 默认缓存商店名称（小写）
   */
  protected string $defaultStore = '';
  /**
   * @var array<string,CacheDriverInterface> 已注册的缓存商店映射（键名为小写）
   */
  protected array $stores;

  /**
   * 从配置中加载缓存商店并注册
   *
   * @param Config $config 框架配置实例，用于读取 cache.stores 和 cache.default
   */
  public function __construct(protected Config $config)
  {
    $stores = $config->get('cache.stores', []);
    // 修复：初始化为空数组，统一由 addStore 以小写 key 存储，避免原始配置的大写 key 与小写 key 共存
    $this->stores = [];
    if (!empty($stores)) {
      // 修复：defaultStore 统一转为小写，与 store() 读取时的小写转换保持一致
      $this->defaultStore = strtolower($config->get('cache.default', array_key_first($stores)));
      foreach ($stores as $key => $driver) $this->addStore($key, $driver);
    }
  }

  /**
   * 注册一个缓存商店到管理器中
   *
   * 支持三种驱动定义方式：类名字符串、完整配置数组、已实例化的驱动对象。
   * 商店名称不区分大小写，内部统一转为小写存储。
   *
   * @param string $name 商店名称（不区分大小写）
   * @param CacheDriverInterface|string|array{driver:string,options:array{string:mixed}|object} $driver 驱动定义，支持类名、配置数组或驱动实例
   * @throws CacheErrorException 驱动类不存在、配置格式错误或未实现接口时抛出
   */
  public function addStore(string $name, CacheDriverInterface|string|array $driver): void
  {
    if (is_string($driver)) {
      if (!class_exists($driver)) {
        throw new CacheErrorException("{$name}缓存驱动配置错误，{$driver}不是一个有效的类");
      }
      $driver = invoke($driver);
    } elseif (is_array($driver)) {
      if (!is_string($driver['driver']) || !class_exists($driver['driver'])) {
        throw new CacheErrorException($name . '缓存驱动配置错误，驱动类不存在');
      }
      $options = $driver['options'] ?? [];
      if (!is_array($options)) {
        throw new CacheErrorException($name . '缓存驱动配置错误，options需为数组');
      }
      $driver = invoke($driver['driver'], $options);
    }
    if (!$driver instanceof CacheDriverInterface) {
      throw new CacheErrorException(
        $name . '缓存驱动配置错误，驱动类需实现' . CacheDriverInterface::class . '接口'
      );
    }
    $this->stores[strtolower($name)] = $driver;
  }

  /**
   * 判断指定名称的缓存商店是否已注册
   *
   * @param string $name 商店名称（不区分大小写）
   * @return bool 已注册返回 true
   */
  public function hasStore(string $name): bool
  {
    return isset($this->stores[strtolower($name)]);
  }

  /**
   * 将方法调用代理转发至默认商店的驱动实例
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数列表
   * @return mixed 驱动方法的返回值
   */
  public function __call(string $name, array $arguments)
  {
    return call_user_func_array([$this->store(), $name], $arguments);
  }

  /**
   * 获取指定名称的缓存商店驱动实例
   *
   * 未指定名称时返回默认商店驱动。商店名称不区分大小写。
   *
   * @param string|null $name 商店名称，null 时使用默认商店
   * @return CacheDriverInterface 缓存驱动实例
   * @throws CacheErrorException 商店为空或指定商店不存在时抛出
   */
  public function store(?string $name = null): CacheDriverInterface
  {
    if (empty($this->stores)) {
      throw new CacheErrorException('缓存商店为空，请先配置缓存商店');
    }
    if (is_null($name)) $name = $this->defaultStore;
    $name = strtolower($name);
    if (isset($this->stores[$name])) return $this->stores[$name];
    throw new CacheErrorException("缓存商店{$name}不存在");
  }
}
