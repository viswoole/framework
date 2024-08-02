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
   * @param RedisConfig $config
   */
  public function __construct(
    RedisConfig $config,
  )
  {
    $this->pool = new RedisPool($config);
    parent::__construct(
      $config->prefix,
      $config->tag_prefix,
      $config->tag_store,
      $config->expire
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
   */
  #[Override] protected function unserialize(mixed $data): mixed
  {
    // 如果是整数则不进行序列化
    if (is_numeric($data)) return $data;
    return parent::unserialize($data);
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
   */
  #[Override] protected function serialize(mixed $data): mixed
  {
    // 如果是整数 直接返回
    if (is_int($data)) return $data;
    return parent::serialize($data);
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
