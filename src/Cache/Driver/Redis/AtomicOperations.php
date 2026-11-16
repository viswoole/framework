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

namespace Viswoole\Cache\Driver\Redis;

use Override;
use RedisException;
use Viswoole\Cache\Exception\CacheErrorException;

/**
 * Redis 原子缓存能力；由 Redis 驱动组合，沿用驱动的连接借还、键前缀和序列化边界。
 * 实现原子消费与集合回收，不直接管理连接生命周期。
 */
trait AtomicOperations
{
  /**
   * @return bool Redis SET NX、Lua pull 及集合回收均提供原子保证
   */
  public function supportsAtomicOperations(): bool
  {
    return true;
  }

  /**
   * 回收成员与空集合注册必须在同一脚本中，避免新成员加入后丢失注册入口。
   * @param string $key 集合键
   * @param string $registry 注册集合键
   * @param string|null $member 要删除的成员；null 表示只回收空集合
   * @return void
   */
  public function pruneSet(string $key, string $registry, ?string $member = null): void
  {
    $script = <<<'LUA'
      if ARGV[1] ~= '' then
        redis.call('SREM', KEYS[1], ARGV[1])
      end
      if redis.call('SCARD', KEYS[1]) == 0 then
        redis.call('SREM', KEYS[2], ARGV[2])
        redis.call('DEL', KEYS[1])
      end
      return 1
      LUA;
    // 集合成员沿用 sAddArray 的 PHP 序列化，不能受值序列化配置影响。
    $arguments = [$this->getCacheKey($key), $this->getCacheKey($registry),
      $member === null ? '' : serialize($member), serialize($key)];
    $result = $this->withConnection(fn(\Redis $redis): mixed => $redis->eval($script, $arguments, 2)
    );
    if ($result !== 1) throw new CacheErrorException("原子回收缓存集合失败：$key");
  }

  /**
   * 原子取出并删除缓存，保证并发消费者至多一方取得原始值。
   *
   * @param string $key 不含前缀的缓存键
   * @return mixed 解码后的缓存值，未命中或过期返回 null
   * @throws RedisException 无法到达 Redis 服务器或脚本执行失败
   */
  #[Override]
  public function pull(string $key): mixed
  {
    $key = $this->getCacheKey($key);
    // 取值与删除必须在同一原子脚本内完成，防止并发请求重复消费一次性凭证。
    // 使用 Lua 避免要求 Redis 6.2 的 GETDEL；删除依据原始值，NULL 缓存也会消费。
    $script = <<<'LUA'
      local value = redis.call('GET', KEYS[1])
      if value ~= false then
        redis.call('DEL', KEYS[1])
      end
      return value
      LUA;
    $value = $this->withConnection(fn(\Redis $redis) => $redis->eval($script, [$key], 1));
    return $value === false ? null : $this->unserialize($value);
  }
}
