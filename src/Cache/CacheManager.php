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

use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Driver\Redis;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Core\Config;

/**
 * 缓存驱动管理器
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

  public function __construct(protected Config $config)
  {
    $stores = $config->get('cache.stores', []);
    $this->stores = $stores;
    if (!empty($this->stores)) {
      $this->defaultStore = $config->get('cache.default', array_keys($stores)[0]);
      foreach ($this->stores as $key => $driver) $this->addStore($key, $driver);
    }
  }

  /**
   * 添加缓存商店
   *
   * @param string $name
   * @param CacheDriverInterface|string $driver
   * @return void
   */
  public function addStore(string $name, CacheDriverInterface|string $driver): void
  {
    if (is_string($driver) && class_exists($driver)) $driver = new $driver();
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
