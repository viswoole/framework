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
 * 基于 Redis 的缓存驱动，通过连接池管理 Redis 连接并提供缓存操作
 *
 * 利用 Redis 原生命令实现 TTL、原子自增/自减、分布式锁等能力，
 * 通过 RedisPool 实现协程安全的连接复用。数组集合操作使用 Redis Set 实现。
 *
 * @see Driver
 * @see RedisPool
 */
class Redis extends Driver
{
  /**
   * 协程上下文键：当前协程持有的连接实例
   *
   * 驱动为全局单例，连接必须按协程隔离：并发协程共用一条连接会导致
   * Redis 协议读写错乱（请求串包/挂死），故存协程上下文而非实例属性
   */
  private const string CONTEXT_CONNECTION_KEY = 'cache.redis.connection';
  /**
   * 协程上下文键：当前协程持有的锁列表
   *
   * 与连接同理，锁的持有记录必须按协程隔离，否则并发协程会误释放彼此的锁
   */
  private const string CONTEXT_LOCK_LIST_KEY = 'cache.redis.lock_list';
  /**
   * @var RedisPool Redis 连接池，负责协程间连接的借出与归还
   */
  private readonly RedisPool $pool;

  /**
   * 初始化 Redis 缓存驱动并创建连接池
   *
   * @param string $host Redis 服务器地址
   * @param int $port Redis 服务器端口
   * @param string $password 认证密码，空字符串表示无密码
   * @param int $db_index Redis 数据库索引（0-15）
   * @param float $timeout 连接超时时间（秒）
   * @param int $retry_interval 重连等待间隔（毫秒）
   * @param float $read_timeout 读取超时时间（秒）
   * @param string $prefix 缓存键前缀
   * @param string $tag_prefix 标签键前缀标识
   * @param int $expire 默认过期时间（秒），0 表示永不过期
   * @param string $tag_store 标签仓库键名，不能为空
   * @param int $pool_max_size 连接池最大连接数
   * @param int $pool_fill_size 连接池最小填充连接数，0 表示不预填充
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
    int              $pool_max_size = 10,
    int              $pool_fill_size = 0
  ) {
    $this->pool = new RedisPool(
      new RedisConfig(
        host: $host,
        port: $port,
        password: $password,
        db_index: $db_index,
        timeout: $timeout,
        retry_interval: $retry_interval,
        read_timeout: $read_timeout,
        prefix: $prefix,
        tag_prefix: $tag_prefix,
        expire: $expire,
        tag_store: $tag_store,
        pool_max_size: $pool_max_size,
        pool_fill_size: $pool_fill_size
      )
    );
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
   * 从连接池获取或复用当前协程的 Redis 连接实例
   *
   * 连接按协程隔离存储于协程上下文；首次借出时注册 defer 回调，
   * 协程结束（含异常退出）自动归还连接，杜绝借出未还导致的连接滞留。
   *
   * @return \Redis Redis 连接实例
   * @throws RedisException 连接失败时抛出
   */
  #[Override] public function connect(): \Redis
  {
    $context = Coroutine::getContext();
    if (!isset($context[self::CONTEXT_CONNECTION_KEY])) {
      $redis = $this->pool->pop();
      $context[self::CONTEXT_CONNECTION_KEY] = $redis;
      if (Coroutine::isCoroutine()) {
        Coroutine::defer(function () use ($redis): void {
          $this->releaseConnection($redis);
        });
      }
    }
    return $context[self::CONTEXT_CONNECTION_KEY];
  }

  /**
   * 幂等归还连接：仅当上下文中仍是该连接时才归还
   *
   * close() 手动归还与 defer 自动归还并发触发时，后到者因上下文键
   * 已被清除而跳过，防止同一连接被重复 put 造成一连接多持有。
   *
   * @param \Redis $redis 待归还的连接实例
   */
  private function releaseConnection(\Redis $redis): void
  {
    $context = Coroutine::getContext();
    if (($context[self::CONTEXT_CONNECTION_KEY] ?? null) === $redis) {
      unset($context[self::CONTEXT_CONNECTION_KEY]);
      $this->pool->put($redis);
    }
  }

  /**
   * 读取当前协程的锁持有列表
   *
   * @return array<string,array{scene:string,secretKey:string,autoUnlock:bool}>
   */
  private function getLockList(): array
  {
    return Coroutine::getContext()[self::CONTEXT_LOCK_LIST_KEY] ?? [];
  }

  /**
   * 记录当前协程持有的锁
   *
   * @param string $lockId 锁ID
   * @param array{scene:string,secretKey:string,autoUnlock:bool} $lockInfo 锁信息
   */
  private function addLock(string $lockId, array $lockInfo): void
  {
    Coroutine::getContext()[self::CONTEXT_LOCK_LIST_KEY][$lockId] = $lockInfo;
  }

  /**
   * 移除当前协程已释放的锁记录
   *
   * @param string $lockId 锁ID
   */
  private function removeLock(string $lockId): void
  {
    $context = Coroutine::getContext();
    if (isset($context[self::CONTEXT_LOCK_LIST_KEY][$lockId])) {
      unset($context[self::CONTEXT_LOCK_LIST_KEY][$lockId]);
    }
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
    $result = $this->get($key);
    if ($result !== null) $this->delete($key);
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
   * Redis 专用的反序列化：将数字字符串还原为原始数值类型
   *
   * Redis 将所有值存储为字符串，整数经 serialize 跳过后以数字字符串存入，
   * 反序列化时需识别数字字符串并还原为 int/float，其余走标准反序列化。
   *
   * @param mixed $data 从 Redis 取出的原始数据
   * @return mixed 还原后的原始值
   */
  #[Override] protected function unserialize(mixed $data): mixed
  {
    // Redis 的 get/set 操作：serialize 对整数直接返回（不序列化），Redis 将其存为字符串。
    // unserialize 时，从 Redis 取出的数据始终为字符串，需要判断是否为数字字符串
    // （对应 serialize 中跳过序列化的整数），如果是则转为数值类型，否则正常反序列化
    if (is_int($data) || is_float($data)) return $data;
    if (is_string($data) && is_numeric($data)) {
      // 数字字符串：对应 serialize 中跳过序列化的整数/浮点数
      // 判断是否为浮点数格式（含小数点或科学计数法）
      if (str_contains($data, '.') || stripos($data, 'e') !== false) {
        return (float)$data;
      }
      return (int)$data;
    }
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
    string    $scene,
    int       $expire = 10,
    bool      $autoUnlock = false,
    int       $retry = 5,
    float|int $sleep = 0.2
  ): string {
    if ($retry <= 0) $retry = 1;
    $result = false;
    $key = $this->getLockKey($scene);
    $lockId = md5(uniqid("{$key}_", true) . '_' . Coroutine::getCid());
    while ($retry-- > 0) {
      // 设置锁/取锁
      $result = $this->connect()->set($key, $lockId, ['NX', 'EX' => $expire]);
      if ($result) {
        // 加入到当前协程的锁列表中
        $this->addLock($lockId, [
          'scene' => $scene,
          'secretKey' => $lockId,
          'autoUnlock' => $autoUnlock
        ]);
        // 取锁成功跳出循环
        break;
      }
      // 未获得锁，休眠重试
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
    DateTime|int|null $expire = null,
    bool         $NX = false
  ): bool {
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
   * Redis 专用的序列化：整数跳过序列化直接存储，由 Redis 原生管理数值类型
   *
   * @param mixed $data 待序列化的缓存数据
   * @return mixed 序列化后的字符串或原始整数
   */
  #[Override] protected function serialize(mixed $data): mixed
  {
    // 如果是整数则直接返回
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
    // 释放当前协程 autoUnlock 的锁（须在归还连接前执行，unlock 依赖连接）
    foreach ($this->getLockList() as $lockId => $lockInfo) {
      if ($lockInfo['autoUnlock']) {
        try {
          $this->unlock($lockId);
        } catch (RedisException) {
        }
      }
    }
    $redis = Coroutine::getContext()[self::CONTEXT_CONNECTION_KEY] ?? null;
    if ($redis instanceof \Redis) {
      $this->releaseConnection($redis);
    }
  }

  /**
   * @inheritDoc
   * @throws RedisException
   */
  #[Override] public function unlock(string $id): bool
  {
    $lockList = $this->getLockList();
    if (!isset($lockList[$id])) return false;
    $lockInfo = $lockList[$id];
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
    if ($result) $this->removeLock($id);
    return (bool)$result;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sAddArray(string $key, array|string $values): false|int
  {
    $key = $this->getCacheKey($key);
    if (is_string($values)) $values = [$values];
    // 修复：sAddArray 必须对所有值使用 parent::serialize() 进行完整序列化，
    // 因为 Redis Set 存储的是字符串，$this->serialize 对整数会跳过序列化直接返回 int，
    // 但 Redis sAdd 会将 int 转为字符串，后续 unserialize 无法正确反序列化原始字符串
    $values = array_map('serialize', $values);
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
    // 修复：与 sAddArray 保持一致，使用 unserialize() 而非 $this->unserialize()
    // 因为 sAddArray 使用 serialize() 存入，getArray 必须使用 unserialize() 取出
    return array_map('unserialize', $result);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function sRemoveArray(string $key, array|string $values): false|int
  {
    if (is_string($values)) $values = [$values];
    // 修复：与 sAddArray 保持一致，使用 serialize() 而非 $this->serialize()
    $values = array_map('serialize', $values);
    $name = $this->getCacheKey($key);
    return $this->connect()->sRem($name, ...$values);
  }
}
