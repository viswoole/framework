<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Viswoole\Core\Channel\ConnectionPool;
use function Swoole\Coroutine\run;

/**
 * 连接池 fork 感知重建测试
 *
 * 池对象创建于 master 进程（服务启动前），fork 后 worker 中的池实例是
 * COW 副本——内含共享 Channel 与计数快照。本测试模拟 PID 变化（反射
 * 改写 createdPid，等价于 fork 后的副本），验证：
 * 1. pop 前自动重建为全新空池，旧进程入池的连接被弃用；
 * 2. put 旧进程的连接被关闭拒绝入池（防污染新池）；
 * 3. PID 未变时行为完全不变（回归基线）。
 *
 * Swoole\Coroutine\Channel 的 push/pop 必须在协程内调用，各场景以 run() 包裹。
 */
class ConnectionPoolForkTest extends TestCase
{
  /** @var object[] 记录被关闭的连接 */
  private array $closed = [];

  private function makePool(int $maxSize = 5): ConnectionPool
  {
    $pool = new class ($maxSize) extends ConnectionPool {
      public array $closed = [];
      public int $seq = 0;

      public function createConnection(): object
      {
        $conn = new stdClass();
        $conn->connSeq = ++$this->seq;
        return $conn;
      }

      public function connectionDetection(mixed $connection): bool
      {
        return true;
      }

      public function closeConnection(mixed $connection): void
      {
        $this->closed[] = $connection;
      }

      public function getConfig(): mixed
      {
        return null;
      }

      /** 暴露底层 Channel 供测试注入/断言 */
      public function getChannelForTest(): \Swoole\Coroutine\Channel
      {
        $prop = new ReflectionProperty(ConnectionPool::class, 'pool');
        return $prop->getValue($this);
      }
    };
    // 引用绑定：匿名类实例的 closed 数组供外层断言
    $this->closed = &$pool->closed;
    return $pool;
  }

  /**
   * 模拟 fork：改写池的创建进程 PID（worker 中的池是 COW 副本，
   * 其 createdPid 仍是父进程 PID，与 getmypid() 不等即触发重建）
   */
  private function simulateFork(ConnectionPool $pool): void
  {
    $prop = new ReflectionProperty(ConnectionPool::class, 'createdPid');
    $prop->setValue($pool, getmypid() + 100000);
  }

  /**
   * 向池内直接注入一条"继承连接"（模拟 master 阶段连接入池）
   */
  private function plantInheritedConnection(ConnectionPool $pool): object
  {
    $inherited = new stdClass();
    $inherited->inherited = true;
    $pool->getChannelForTest()->push($inherited);
    return $inherited;
  }

  public function testPopRebuildsPoolAndDiscardsInheritedConnections(): void
  {
    run(function (): void {
      $pool = $this->makePool();
      $inherited = $this->plantInheritedConnection($pool);
      $this->simulateFork($pool);
      $conn = $pool->pop(0.1);
      self::assertNotSame($inherited, $conn, '不得返回旧进程入池的连接');
      self::assertSame(1, $conn->connSeq, '应返回本进程新建的连接');
      self::assertSame(0, $pool->getChannelForTest()->length(), '重建后的池应为空（继承连接被弃用）');
    });
  }

  public function testPutRejectsConnectionFromOldProcess(): void
  {
    run(function (): void {
      $pool = $this->makePool();
      $inherited = new stdClass();
      $inherited->inherited = true;
      $this->simulateFork($pool);
      $pool->put($inherited);
      self::assertSame([$inherited], $this->closed, '旧进程的连接应被关闭而非入池');
      self::assertSame(0, $pool->getChannelForTest()->length(), '新池不得被旧连接污染');
      // 重建后池可用：新建连接正常 pop
      $conn = $pool->pop(0.1);
      self::assertSame(1, $conn->connSeq);
    });
  }

  public function testNormalPopPutUnchangedWhenPidMatches(): void
  {
    run(function (): void {
      $pool = $this->makePool();
      $conn = $pool->pop(0.1);
      self::assertSame(1, $conn->connSeq, '首次 pop 应新建连接');
      $pool->put($conn);
      // PID 未变（无 fork）：put 走正常归还（检测通过入池），pop 复用同一连接
      self::assertSame([], $this->closed, '正常归还不应关闭连接');
      self::assertSame($conn, $pool->pop(0.1), '池应复用归还的连接');
    });
  }
}
