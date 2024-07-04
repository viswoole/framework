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

namespace Viswoole\Cache\Driver;

use DateTime;
use Override;
use RedisException;
use Swoole\Coroutine\System;
use Viswoole\Cache\Driver;
use Viswoole\Cache\Exception\CacheErrorException;
use Viswoole\Cache\RedisConfig;
use Viswoole\Cache\RedisPool;
use Viswoole\Core\Coroutine;

/**
 * Redis缓存驱动
 */
class Redis extends Driver
{
  /**
   * @var array 锁
   */
  private array $lockList = [];
  /**
   * @var \Redis 当前连接实例
   */
  private \Redis $redis;
  /**
   * @var RedisPool 连接池
   */
  private readonly RedisPool $pool;

  /**
   * @param string $host 连接地址
   * @param int $port 连接端口
   * @param string $password 密码
   * @param int $db_index redis数据库 0-15
   * @param float $timeout 连接超时时间
   * @param int $retry_interval 连接重试时间等待单位毫秒
   * @param float $read_timeout 读取超时时间
   * @param string $prefix 缓存前缀
   * @param int $expire 过期时间，单位秒
   * @param string $tag_store 标签仓库名称(用于存储标签映射列表),不能为空
   * @param int $pool_max_size 连接池最大长度
   * @param int $pool_fill_size 连接池最小长度，如果为0则默认不填充连接池
   */
  public function __construct(
    string           $host = '127.0.0.1',
    int              $port = 6379,
    string           $password = '',
    int              $db_index = 0,
    float            $timeout = 0,
    int              $retry_interval = 1000,
    float            $read_timeout = 0,
    protected string $prefix = '',
    protected string $tag_prefix = 'tag:',
    protected int    $expire = 0,
    protected string $tag_store = 'TAG_STORE',
    int              $pool_max_size = 64,
    int              $pool_fill_size = 0
  )
  {
    $this->pool = new RedisPool(new RedisConfig(...func_get_args()));
    parent::__construct(
      $prefix,
      $tag_prefix,
      $tag_store,
      $expire
    );
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function inc(string $key, int $step = 1): false|int
  {
    $key = $this->getCacheKey($key);
    return $this->connect()->incrBy($key, $step);
  }

  /**
   * @inheritDoc
   * @return \Redis
   */
  #[Override] public function connect(): \Redis
  {
    if (!isset($this->redis)) {
      $this->redis = $this->pool->pop();
    }
    return $this->redis;
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function dec(string $key, int $step = 1): false|int
  {
    $key = $this->getCacheKey($key);
    return $this->connect()->decrBy($key, $step);
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function pull(string $key): mixed
  {
    $result = $this->get($key, false);
    if ($result !== false) $this->delete($key);
    return $result;
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function get(string $key, mixed $default = null): mixed
  {
    $key = $this->getCacheKey($key);
    $value = $this->connect()->get($key);
    if (false === $value) return $default;
    return $this->unserialize($value);
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function delete(array|string $keys): false|int
  {
    if (is_string($keys)) $keys = [$keys];
    foreach ($keys as $index => $key) {
      $keys[$index] = $this->getCacheKey($key);
    }
    return $this->connect()->del(...$keys);
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function has(string $key): bool
  {
    return (bool)$this->connect()->exists($this->getCacheKey($key));
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function clear(): bool
  {
    return (bool)$this->connect()->flushDB();
  }

  /**
   * @inheritDoc
   * @throws RedisException
   */
  #[Override] public function lock(
    string    $scene, int $expire = 10, bool $autoUnlock = false, int $retry = 5,
    float|int $sleep = 0.2
  ): string
  {
    if ($retry <= 0) $retry = 1;
    $result = false;
    $key = $this->getLockKey($scene);
    $lockId = md5(uniqid("{$key}_", true) . '_' . Coroutine::getCid());
    while ($retry-- > 0) {
      // 设置锁/取锁
      $result = $this->connect()->set($key, $lockId, ['NX', 'EX' => $expire]);
      if ($result) {
        // 加入到锁列表中
        $this->lockList[$lockId] = [
          'scene' => $scene,
          'secretKey' => $lockId,
          'autoUnlock' => $autoUnlock
        ];
        // 取锁成功跳出循环
        break;
      }
      //未获得锁 休眠
      System::sleep($sleep);
    }
    if ($result === false) throw new CacheErrorException('数据系统繁忙，请稍后重试');
    return $lockId;
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function set(
    string       $key,
    mixed        $value,
    DateTime|int $expire = null,
    bool         $NX = false
  ): bool
  {
    if (is_null($expire)) $expire = $this->expire;
    $key = $this->getCacheKey($key);
    $expire = $this->formatExpireTime($expire);
    $value = $this->serialize($value);
    $options = [];
    if ($NX) $options[] = 'NX';
    if ($expire > 0) $options['EX'] = $expire;
    return $this->connect()->set($key, $value, $options);
  }

  /**
   * @inheritDoc
   * @throws RedisException
   */
  #[Override] public function ttl(string $key): false|int
  {
    $key = $this->getCacheKey($key);
    $result = $this->connect()->ttl($key);
    if ($result === -2 || $result === false) return false;
    return $result;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function close(): void
  {
    foreach ($this->lockList as $lockId => $lockInfo) {
      if ($lockInfo['autoUnlock']) {
        try {
          $this->unlock($lockId);
        } catch (RedisException) {
        }
      }
    }
    if (isset($this->redis)) {
      $this->pool->put($this->redis);
      unset($this->redis);
    }
  }

  /**
   * @inheritDoc
   * @throws RedisException
   */
  #[Override] public function unlock(string $id): bool
  {
    if (empty($this->lockList)) return false;
    if (!isset($this->lockList[$id])) return false;
    $lockInfo = $this->lockList[$id];
    $scene = $this->getLockKey($lockInfo['scene']);
    $script = <<<LUA
                local key=KEYS[1]
                local value=ARGV[1]
                if(redis.call('get', key) == value)
                then
                return redis.call('del', key)
                end
                LUA;
    $value = $lockInfo['secretKey'];
    $result = $this->connect()->eval($script, [$scene, $value], 1);
    if ($result) unset($this->lockList[$id]);
    return (bool)$result;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sAddArray(string $key, array|string $values): false|int
  {
    $key = $this->getCacheKey($key);
    if (is_string($values)) $values = [$values];
    // 序列化
    $values = array_map([$this, 'serialize'], $values);
    return $this->connect()->sAdd($key, ...$values);
  }

  /**
   * @inheritDoc
   * @throws RedisException 无法到达 Redis 服务器
   */
  #[Override] public function getArray(string $key): array|false
  {
    $name = $this->getCacheKey($key);
    $result = $this->connect()->sMembers($name);
    if ($result === false) return false;
    return array_map([$this, 'unserialize'], $result);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sRemoveArray(string $key, array|string $values): false|int
  {
    if (is_string($values)) $values = [$values];
    $values = array_map([$this, 'serialize'], $values);
    $name = $this->getCacheKey($key);
    return $this->connect()->sRem($name, ...$values);
  }
}
