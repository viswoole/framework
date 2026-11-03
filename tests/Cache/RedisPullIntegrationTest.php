<?php

declare(strict_types=1);

/* 职责：用真实 Redis 验证一次性消费与并发安全；仅清理测试独占前缀。 */

namespace Viswoole\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;
use Throwable;
use Viswoole\Cache\Driver\Redis;

/** Redis pull 的真实存储与并发回归测试。 */
final class RedisPullIntegrationTest extends TestCase
{
  private Redis $cache;

  /** @return void 创建独占命名空间，不清空共享 Redis 数据库。 */
  protected function setUp(): void
  {
    $host = getenv('REDIS_HOST') ?: 'host.docker.internal';
    $this->cache = new Redis($host, prefix: 'test:pull:' . bin2hex(random_bytes(8)) . ':');
  }

  /** @return void 删除本测试键并归还连接。 */
  protected function tearDown(): void
  {
    $this->cache->delete(['ticket', 'custom', 'expired']);
    $this->cache->close();
  }

  /** @return void 各种合法值必须在取出后删除，尤其不能遗漏 NULL 或 false。 */
  public function testStoredValuesAreConsumedExactlyOnce(): void
  {
    foreach ([null, false, '', 0, 42, 1.25, '42', ['user_id' => '123']] as $value) {
      self::assertTrue($this->cache->set('ticket', $value, 60));
      self::assertSame($value, $this->cache->pull('ticket'));
      self::assertFalse($this->cache->has('ticket'));
      self::assertNull($this->cache->pull('ticket'));
    }
  }

  /** @return void 原子命令仍须走配置的解码回调。 */
  public function testCustomSerializationIsPreserved(): void
  {
    $this->cache->setSerialize('json_encode', static fn (string $value): mixed => json_decode($value, true));
    $value = ['user_id' => '123', 'host' => 'example.com'];
    self::assertTrue($this->cache->set('custom', $value, 60));
    self::assertSame($value, $this->cache->pull('custom'));
    self::assertNull($this->cache->pull('custom'));
  }

  /** @return void 已过期键应返回 NULL，不能重新产生有效票据。 */
  public function testExpiredValueCannotBeConsumed(): void
  {
    self::assertTrue($this->cache->set('expired', 'ticket', 60));
    $this->cache->connect()->pExpire($this->cache->getCacheKey('expired'), 1);
    usleep(10000);
    self::assertNull($this->cache->pull('expired'));
  }

  /** @return void 多个独立协程连接竞争同一票据，恰好一个消费者成功。 */
  public function testConcurrentConsumersHaveExactlyOneWinner(): void
  {
    self::assertTrue($this->cache->set('ticket', ['user_id' => '123'], 60));
    $hookFlags = Runtime::getHookFlags();
    try {
      // phpredis 的网络 I/O 必须让出协程，否则测试只会串行访问 Redis。
      Runtime::setHookFlags($hookFlags | SWOOLE_HOOK_TCP);
      $results = $this->consumeConcurrently();
    } finally {
      Runtime::setHookFlags($hookFlags);
    }
    self::assertCount(32, $results);
    self::assertSame(1, count(array_filter($results, static fn (mixed $value): bool => $value === ['user_id' => '123'])));
    self::assertSame(31, count(array_filter($results, static fn (mixed $value): bool => $value === null)));
    self::assertNull($this->cache->pull('ticket'));
  }

  /** @return list<mixed> 同时放行 32 个消费者，保留异常交给调用方断言。 */
  private function consumeConcurrently(): array
  {
    $results = [];
    Coroutine\run(function () use (&$results): void {
      $ready = new Channel(32);
      $start = new Channel(32);
      $completed = new Channel(32);
      for ($index = 0; $index < 32; ++$index) {
        Coroutine::create(function () use ($ready, $start, $completed): void {
          $ready->push(true);
          $start->pop();
          try {
            $completed->push($this->cache->pull('ticket'));
          } catch (Throwable $error) {
            $completed->push($error);
          }
        });
      }
      for ($index = 0; $index < 32; ++$index) {
        self::assertTrue($ready->pop(5));
        $start->push(true);
      }
      for ($index = 0; $index < 32; ++$index) {
        $results[] = $completed->pop(5);
      }
    });
    return $results;
  }

}
