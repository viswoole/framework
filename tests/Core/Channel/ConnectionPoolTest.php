<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core\Channel;

use Override;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Viswoole\Core\Exception\ConnectionPoolException;

/**
 * 连接池生命周期测试
 *
 * 覆盖非协程环境（CLI 脚本）下连接必须被显式关闭、协程环境下连接正常归还的关键行为。
 */
class ConnectionPoolTest extends TestCase
{
  /**
   * 非协程环境下 put() 应关闭连接而非丢弃
   *
   * 回归验证：此前 put() 在非协程环境直接 return，导致脚本循环调用
   * Db::execute() 时每次新建的连接永不释放，最终打爆 MySQL 连接数（1040）。
   *
   * @return void
   */
  public function testPutInNonCoroutineClosesConnection(): void
  {
    $pool = new FakeConnectionPool(4);
    $connection = $pool->createForTest();

    $pool->put($connection);

    static::assertSame([$connection], $pool->closed);
    static::assertSame(0, $pool->length(), '非协程下连接不应归还到连接池');
  }

  /**
   * 非协程环境下 pop() 直接创建新连接，不改变连接池长度
   *
   * @return void
   */
  public function testPopInNonCoroutineCreatesConnectionWithoutPooling(): void
  {
    $pool = new FakeConnectionPool(4);

    $connection = $pool->pop();

    static::assertNotNull($connection);
    static::assertSame(1, $pool->createdCount);
    static::assertSame(0, $pool->length());
    static::assertSame([], $pool->closed);
  }

  /**
   * 协程环境下 put() 应将可用连接归还到连接池，且不触发关闭
   *
   * @return void
   */
  public function testPutInCoroutineReturnsConnectionToPool(): void
  {
    $pool = new FakeConnectionPool(4);
    $connection = $pool->createForTest();

    Coroutine\run(function () use ($pool, $connection) {
      $pool->put($connection);
    });

    static::assertSame(1, $pool->length(), '协程下连接应归还到连接池');
    static::assertSame([], $pool->closed, '可用连接归还时不应被关闭');
  }

  /**
   * 非协程环境下 fill() 不填充连接池
   *
   * 连接池仅在协程环境下才有复用意义，非协程环境直接跳过。
   *
   * @return void
   */
  public function testFillInNonCoroutineDoesNothing(): void
  {
    $pool = new FakeConnectionPool(10);

    $pool->fill(3);

    static::assertSame(0, $pool->length(), '非协程环境不应填充连接池');
    static::assertSame(0, $pool->createdCount);
  }

  /**
   * 协程环境下 fill() 应填充连接池
   *
   * @return void
   */
  public function testFillInCoroutineFillsPool(): void
  {
    $pool = new FakeConnectionPool(10);

    Coroutine\run(function () use ($pool) {
      $pool->fill(2);
    });

    static::assertSame(2, $pool->length(), '协程 fill 应填充指定数量的连接');
    static::assertSame(2, $pool->createdCount);
  }

  /**
   * fill() 超过 max_size 时只填充到上限，避免 Channel push 死锁
   *
   * @return void
   */
  public function testFillDoesNotExceedMaxSize(): void
  {
    $pool = new FakeConnectionPool(3);

    Coroutine\run(function () use ($pool) {
      $pool->fill(10);
    });

    static::assertSame(3, $pool->length(), '填充不应超过连接池最大容量');
  }

  /**
   * 并发协程同时建连时，make() 递归深度应按协程隔离，不误判为递归过深
   *
   * 回归验证：此前递归深度用实例属性计数，多协程并发 pop() 建连时互相累加，
   * 第 4 个并发协程即误抛"已达到最大重试次数(3次)"。
   * createConnection() 内挂起制造协程切换点，确保多个协程同时停留在 make() 中。
   *
   * @return void
   */
  public function testConcurrentCoroutinesDoNotTriggerFalseMaxRetry(): void
  {
    $pool = new class(10) extends FakeConnectionPool {
      #[Override]
      protected function createConnection(): mixed
      {
        // 挂起 1ms，让其他协程切入并同时进入 make()，复现深度互相累加的并发场景
        Coroutine::sleep(0.001);
        return parent::createConnection();
      }
    };
    $errors = [];
    Coroutine\run(static function () use ($pool, &$errors): void {
      $wg = new WaitGroup();
      for ($i = 0; $i < 8; $i++) {
        $wg->add();
        go(static function () use ($pool, $wg, &$errors): void {
          try {
            $pool->pop();
          } catch (ConnectionPoolException $e) {
            $errors[] = $e->getMessage();
          } finally {
            $wg->done();
          }
        });
      }
      $wg->wait();
    });
    static::assertSame([], $errors, '并发协程建连不应误判为递归过深');
  }
}
