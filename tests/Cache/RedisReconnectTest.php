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

namespace Viswoole\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Viswoole\Cache\Driver\Redis;
use Viswoole\Core\Coroutine;

/**
 * Redis 非协程连接复用与断线重连测试
 *
 * 非协程环境下连接被进程级上下文长期持有（无 defer 自动归还），
 * 复用前必须 ping 检测存活：失效则关闭重建，避免 Redis 重启后复用死连接。
 *
 * 依赖测试环境中的真实 Redis（与 RedisTest 一致，经 docker-compose 提供）。
 */
class RedisReconnectTest extends TestCase
{
  /** @var string Redis 驱动在协程上下文中缓存连接的键名（源码私有常量的镜像） */
  private const string CONTEXT_CONNECTION_KEY = 'cache.redis.connection';
  /** @var Redis|null 当前测试使用的缓存驱动实例 */
  private ?Redis $cache = null;

  /**
   * @return void
   */
  protected function setUp(): void
  {
    // REDIS_HOST 由 docker-compose 设置为 redis（Compose 网络内解析）
    // 回退到 host.docker.internal 供 PhpStorm 等 IDE 直接创建容器时通过宿主机映射端口连接
    $host = getenv('REDIS_HOST') ?: 'host.docker.internal';
    $this->cache = new Redis($host);
  }

  /**
   * @return void
   */
  protected function tearDown(): void
  {
    // 清理进程级上下文中缓存的连接，避免污染同进程后续测试的复用路径
    $context = Coroutine::getContext();
    $redis = $context[self::CONTEXT_CONNECTION_KEY] ?? null;
    if ($redis instanceof \Redis) {
      unset($context[self::CONTEXT_CONNECTION_KEY]);
      try {
        $redis->close();
      } catch (\RedisException) {
        // 已断开的连接关闭时会抛异常，直接丢弃即可
      }
    }
    $this->cache = null;
  }

  /**
   * 非协程环境下多次 connect() 应复用同一连接（进程级缓存语义）
   *
   * @return void
   */
  public function testNonCoroutineConnectReusesCachedConnection(): void
  {
    $first = $this->cache->connect();
    $second = $this->cache->connect();

    static::assertInstanceOf(\Redis::class, $first);
    static::assertSame($first, $second, '非协程复用路径应返回进程级缓存的同一连接');
  }

  /**
   * 上下文中的失效连接应被关闭并重建新连接
   *
   * 用 mock 模拟失效连接（ping 抛异常）：phpredis 6.x 对 close() 后的命令
   * 会透明重连，无法用进程内 close 可靠模拟断线，故直接以 mock 驱动
   * "复用前检测 → 关闭 → 重建"的完整路径，行为确定性不受 phpredis 版本影响。
   *
   * @return void
   */
  public function testDeadContextConnectionIsClosedAndRebuilt(): void
  {
    $dead = $this->createMock(\Redis::class);
    $dead->method('ping')->willThrowException(new \RedisException('Connection closed'));
    $isClosed = false;
    $dead->method('close')->willReturnCallback(
      static function () use (&$isClosed): bool {
        $isClosed = true;
        return true;
      }
    );
    // 塞入进程级上下文（框架 Coroutine 封装的 mock_context，与驱动读取一致），
    // 模拟上一轮操作遗留的失效连接
    Coroutine::getContext()[self::CONTEXT_CONNECTION_KEY] = $dead;

    $reconnected = $this->cache->connect();

    static::assertTrue($isClosed, '失效连接应被显式关闭');
    static::assertNotSame($dead, $reconnected, '应重建新连接而非复用失效连接');
    static::assertInstanceOf(\Redis::class, $reconnected);
  }

  /**
   * db_index 非 0 时，复用路径应恢复库选择状态
   *
   * phpredis 6.x 断线透明重连后 SELECT 状态丢失（静默落回 db 0），
   * isAlive 检测需对非 0 库执行幂等 SELECT 恢复，防止缓存读写错库。
   *
   * @return void
   */
  public function testAliveCheckRestoresSelectedDatabase(): void
  {
    // db_index=1 的驱动实例（连接建立时会 SELECT 1）
    $host = getenv('REDIS_HOST') ?: 'host.docker.internal';
    $this->cache = new Redis($host, db_index: 1);

    $connection = $this->cache->connect();

    static::assertInstanceOf(\Redis::class, $connection);
    // 二次 connect 走复用路径：isAlive 内部 SELECT 恢复 db 状态，
    // 连接上的读写必须仍落在 db 1（写入后经原生连接在 db 1 上可读）
    static::assertSame($connection, $this->cache->connect());
    $this->cache->set('reconnect_db_probe', 'viswoole');
    $probe = new \Redis();
    $probe->connect($host, 6379);
    try {
      $probe->select(1);
      // 驱动写入会经 serialize 序列化后存储，探针直接读取原始值比对
      static::assertSame(
        serialize('viswoole'),
        $probe->get('reconnect_db_probe'),
        '复用路径的写入应落在 db_index=1'
      );
    } finally {
      $probe->del('reconnect_db_probe');
      $probe->close();
    }
  }
}
