<?php

declare(strict_types=1);

/* 职责：复现提交后回调对协程事务析构清理的影响，不修改事务实现或连接池。 */

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel;
use Viswoole\Database\ConnectManager;

use function Swoole\Coroutine\run;

/**
 * 提交后回调生命周期审查用例
 */
final class AfterCommitLifecycleReviewTest extends TestCase
{
  /**
   * 未结束事务应在协程退出时回滚
   *
   * 回调捕获所属管理器是合法用法，不能使连接清理依赖不确定的循环垃圾回收。
   *
   * @return void 协程结束后连接应已退出事务
   */
  public function testCapturedManagerCannotDelayCoroutineTransactionCleanup(): void
  {
    $connection = new PDO('sqlite::memory:');
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    $wasGcEnabled = gc_enabled();
    gc_disable();
    try {
      run(static function () use ($channel): void {
        $manager = ConnectManager::factory();
        $manager->start();
        $manager->pop($channel, 'write');
        $manager->afterCommit(static function () use ($manager): void {
          $manager->transactionLevel();
        });
        // 模拟异常出口没有显式提交或回滚，沿用框架协程结束时析构回滚的契约。
      });
      $hasTransactionAfterExit = $connection->inTransaction();
    } finally {
      // 清理仅用于隔离失败用例，不把 GC 当作生产修复方案。
      gc_collect_cycles();
      if ($connection->inTransaction()) { $connection->rollBack(); }
      if ($wasGcEnabled) { gc_enable(); }
    }
    self::assertFalse($hasTransactionAfterExit, '捕获管理器的回调导致协程退出后仍持有事务，直到循环 GC 才清理');
  }

  /**
   * 不注册回调时验证原有析构清理
   *
   * 同一真实连接和协程退出路径作为对照，排除 PDO 或测试环境原因。
   *
   * @return void 原有路径立即回滚
   */
  public function testUnclosedTransactionWithoutCallbackIsCleanedOnCoroutineExit(): void
  {
    $connection = new PDO('sqlite::memory:');
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    run(static function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->pop($channel, 'write');
    });
    self::assertFalse($connection->inTransaction());
  }

  /**
   * 首次提交后再次开启的事务也须退出清理
   *
   * 同一协程可使用同一管理器多次，第一次提交不能使后续遗留事务脱离清理钩子。
   *
   * @return void 首次写入保留，第二次写入回滚，回调没有误执行
   */
  public function testNewTransactionAfterCommitIsCleanedOnExit(): void
  {
    $connection = new PDO('sqlite::memory:');
    $connection->exec('CREATE TABLE evidence(id INTEGER PRIMARY KEY)');
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    $called = false;
    run(static function () use ($channel, $connection, &$called): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->pop($channel, 'write');
      $connection->exec('INSERT INTO evidence VALUES(1)');
      $manager->commit();
      $manager->start();
      $manager->pop($channel, 'write');
      $connection->exec('INSERT INTO evidence VALUES(2)');
      $manager->afterCommit(static function () use ($manager, &$called): void {
        $called = true;
        $manager->transactionLevel();
      });
    });
    self::assertFalse($connection->inTransaction());
    self::assertSame([1], $connection->query('SELECT id FROM evidence')->fetchAll(PDO::FETCH_COLUMN));
    self::assertFalse($called);
  }

}
