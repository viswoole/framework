<?php

declare(strict_types=1);

/* 职责：用真实 SQLite 延迟约束验证明确提交失败不能触发成功回调，不模拟提交方法。 */

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\Channel;
use Viswoole\Database\ConnectManager;

/**
 * 提交确认边界审查用例
 */
final class AfterCommitConfirmationReviewTest extends TestCase
{
  /**
   * 静默模式提交失败不得发送成功通知
   *
   * SQLite 延迟外键在 COMMIT 时检查，PDO 静默模式返回 false 而不抛出异常。
   *
   * @return void 数据未提交、回调未执行且错误已传播
   */
  public function testSilentCommitFailureCannotInvokeSuccessCallback(): void
  {
    $connection = $this->createDeferredConstraintConnection();
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    $manager = new ConnectManager();
    $previous = Context::get('$_db_transaction');
    Context::set('$_db_transaction', $manager);
    $called = false;
    $commitError = null;
    try {
      $manager->start();
      $manager->pop($channel, 'write');
      self::assertSame(1, $connection->exec('INSERT INTO children VALUES(99)'));
      $manager->afterCommit(static function () use (&$called): void { $called = true; });
      try {
        $manager->commit();
      } catch (Throwable $error) {
        $commitError = $error;
      }
    } finally {
      while ($manager->transactionLevel() > 0) { $manager->rollBack(); }
      Context::set('$_db_transaction', $previous);
    }
    self::assertSame(0, (int)$connection->query('SELECT COUNT(*) FROM children')->fetchColumn());
    self::assertFalse($called, 'COMMIT 返回 false 后数据已回滚，却仍执行了提交成功回调');
    self::assertInstanceOf(Throwable::class, $commitError);
  }

  /**
   * 验证真实驱动返回明确提交失败
   *
   * 对照用例排除延迟约束配置错误，失败必须发生在 COMMIT 而非 INSERT。
   *
   * @return void SQLite 真实提交失败已确认
   */
  public function testNativeDriverReportsFalseForDeferredConstraintFailure(): void
  {
    $connection = $this->createDeferredConstraintConnection();
    $connection->beginTransaction();
    self::assertSame(1, $connection->exec('INSERT INTO children VALUES(99)'));
    self::assertFalse($connection->commit());
    self::assertTrue($connection->inTransaction());
    self::assertSame('23000', $connection->errorCode());
    $connection->rollBack();
  }

  /**
   * 建立提交时才校验外键的真实数据库
   *
   * 该 PDO 模式可经通道 options 配置，不需要修改驱动实现。
   *
   * @return PDO 已启用延迟外键与静默错误模式的连接
   */
  private function createDeferredConstraintConnection(): PDO
  {
    $connection = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    $connection->exec('PRAGMA foreign_keys=ON');
    $connection->exec('CREATE TABLE parents(id INTEGER PRIMARY KEY)');
    $connection->exec('CREATE TABLE children(parent_id INTEGER REFERENCES parents(id) DEFERRABLE INITIALLY DEFERRED)');
    return $connection;
  }
}
