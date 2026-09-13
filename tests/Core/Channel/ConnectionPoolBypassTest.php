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

namespace Viswoole\Tests\Core\Channel;

use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;
use Viswoole\Core\Server\ProcessRole;

/**
 * 连接池进程角色旁路测试
 *
 * 验证 shouldBypassPool 判定：server 模式下非 worker 进程（master/manager）
 * 一律走一次性短连接（直连直关、不触碰池），worker 进程保持池化借还。
 *
 * 角色为进程级静态状态，每个用例前后重置为 CLI，避免污染其他测试文件。
 */
class ConnectionPoolBypassTest extends TestCase
{
  /**
   * 通过反射重置静态角色为 CLI（默认值）
   */
  private static function resetRole(): void
  {
    // PHP 8.4 中私有静态属性反射读写无需 setAccessible（已于 8.1 起默认可访问）
    $role = new ReflectionProperty(ProcessRole::class, 'role');
    $role->setValue(null, ProcessRole::CLI);
  }

  /**
   * @return void
   */
  protected function setUp(): void
  {
    self::resetRole();
  }

  /**
   * @return void
   */
  protected function tearDown(): void
  {
    self::resetRole();
  }

  /**
   * master 角色（含协程化的 onStart 场景）：pop 直接建连不入池
   *
   * 回归验证：Swoole 6.x 事件回调已协程化，此前 isCoroutine 守卫拦不住
   * master 协程，master 中的 pop 会将连接放入 fork 共享的 Channel 污染 worker。
   *
   * @return void
   */
  public function testPopInMasterCoroutineBypassesPool(): void
  {
    ProcessRole::markAsMaster();
    $pool = new FakeConnectionPool(4);

    Coroutine\run(static function () use ($pool): void {
      $connection = $pool->pop();
      static::assertNotNull($connection);
      static::assertSame(1, $pool->createdCount, 'master 协程 pop 应直接建连');
      static::assertSame(0, $pool->length(), 'master 协程 pop 的连接不得进入连接池');
    });
  }

  /**
   * master 角色：put 直接关闭连接，不归还池
   *
   * @return void
   */
  public function testPutInMasterCoroutineClosesConnection(): void
  {
    ProcessRole::markAsMaster();
    $pool = new FakeConnectionPool(4);
    $connection = $pool->createForTest();

    Coroutine\run(static function () use ($pool, $connection): void {
      $pool->put($connection);
    });

    static::assertSame([$connection], $pool->closed, 'master 协程 put 应关闭连接而非归还');
    static::assertSame(0, $pool->length(), 'master 协程归还的连接不得进入连接池');
  }

  /**
   * master 角色：fill() 不填充连接池
   *
   * @return void
   */
  public function testFillInMasterDoesNothing(): void
  {
    ProcessRole::markAsMaster();
    $pool = new FakeConnectionPool(10);

    Coroutine\run(static function () use ($pool): void {
      $pool->fill(3);
    });

    static::assertSame(0, $pool->length(), 'master 进程不得填充连接池');
    static::assertSame(0, $pool->createdCount);
  }

  /**
   * worker 角色（workerStart 之后）：恢复池化借还行为
   *
   * @return void
   */
  public function testPopPutInWorkerCoroutineUsesPool(): void
  {
    ProcessRole::markAsWorker();
    $pool = new FakeConnectionPool(4);

    Coroutine\run(static function () use ($pool): void {
      $connection = $pool->pop();
      static::assertNotNull($connection);
      $pool->put($connection);
    });

    static::assertSame(1, $pool->length(), 'worker 协程归还的连接应进入连接池复用');
    static::assertSame([], $pool->closed, 'worker 协程可用连接归还时不应被关闭');
  }

  /**
   * CLI 角色（默认）：协程内保持池化借还，行为与既有语义一致
   *
   * @return void
   */
  public function testCliCoroutineStillUsesPool(): void
  {
    $pool = new FakeConnectionPool(4);

    Coroutine\run(static function () use ($pool): void {
      $connection = $pool->pop();
      $pool->put($connection);
    });

    static::assertSame(1, $pool->length(), 'CLI 协程应保持池化借还行为');
  }

  /**
   * 短连接归还时 closeConnection 抛异常不应向外传播
   *
   * 回归验证（加固 H1）：manager 回调（非协程短连接）经 withConnection 的
   * finally 归还连接，若归还瞬间对端恰好断开导致 close 抛异常，异常会从
   * finally 中冒出掩盖调用方的业务异常。短连接归还属兜底清理，与
   * fork-aware 分支的防御语义一致，必须吞掉清理异常。
   *
   * @return void
   */
  public function testPutInMasterCoroutineSwallowsCloseFailure(): void
  {
    ProcessRole::markAsMaster();
    $pool = new class(4) extends FakeConnectionPool {
      #[Override]
      protected function closeConnection(mixed $connection): void
      {
        throw new RuntimeException('mock: 对端已断开，关闭失败');
      }
    };
    $connection = $pool->createForTest();
    $caught = null;

    Coroutine\run(static function () use ($pool, $connection, &$caught): void {
      try {
        $pool->put($connection);
      } catch (Throwable $e) {
        $caught = $e;
      }
    });

    static::assertNull(
      $caught,
      '短连接归还的清理异常不应向外传播: ' . ($caught ? $caught->getMessage() : '')
    );
  }
}
