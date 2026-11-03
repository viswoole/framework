<?php

declare(strict_types=1);

/* 职责：验证 Redis pull 单命令消费、键前缀与解码契约，不依赖外部 Redis。 */

namespace Viswoole\Tests\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis as RedisConnection;
use RedisException;
use Viswoole\Cache\Driver\Redis;

/** Redis 一次性消费回归测试。 */
final class RedisPullTest extends TestCase
{
  /**
   * 原子消费只能发出一次 EVAL，不能退回独立 GET/DEL。
   *
   * @param mixed $stored Redis 原始返回值
   * @param mixed $expected 解码结果
   * @return void
   */
  #[DataProvider('provideValues')]
  public function testPullUsesOneAtomicCommand(mixed $stored, mixed $expected): void
  {
    $connection = $this->createMock(RedisConnection::class);
    $connection->expects(self::never())->method('get');
    $connection->expects(self::never())->method('del');
    $connection->expects(self::once())->method('eval')
      ->with(self::isType('string'), ['test:ticket'], 1)
      ->willReturn($stored);
    $cache = $this->getMockBuilder(Redis::class)
      ->setConstructorArgs(['prefix' => 'test:'])
      ->onlyMethods(['connect'])->getMock();
    $cache->method('connect')->willReturn($connection);

    self::assertSame($expected, $cache->pull('ticket'));
  }

  /** @return array<string, array{mixed, mixed}> 缓存值与 Redis miss 的边界样例 */
  public static function provideValues(): array
  {
    return [
      'missing' => [false, null],
      'null' => [serialize(null), null],
      'false' => [serialize(false), false],
      'empty' => [serialize(''), ''],
      'zero' => ['0', 0],
      'integer' => ['42', 42],
      'float' => [serialize(1.25), 1.25],
      'numeric string' => [serialize('42'), '42'],
      'bundle' => [serialize(['user_id' => '123']), ['user_id' => '123']],
    ];
  }

  /** @return void Redis 命令失败必须向调用方传播，不能伪装为未命中。 */
  public function testPullPropagatesRedisFailure(): void
  {
    $connection = $this->createMock(RedisConnection::class);
    $connection->method('eval')->willThrowException(new RedisException('connection lost'));
    $cache = $this->getMockBuilder(Redis::class)->onlyMethods(['connect'])->getMock();
    $cache->method('connect')->willReturn($connection);

    $this->expectException(RedisException::class);
    $this->expectExceptionMessage('connection lost');
    $cache->pull('ticket');
  }
}
