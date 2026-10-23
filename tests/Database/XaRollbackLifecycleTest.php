<?php

declare(strict_types=1);

/** XA 回滚生命周期回归：模拟 MySQL ACTIVE 约束及回滚故障，禁止脏连接归池。 */

namespace Viswoole\Tests\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\Transaction\XaBranchState;
use Viswoole\Database\Transaction\XaJournal;

require_once __DIR__ . '/XaTransactionTest.php';

final class XaRollbackLifecycleTest extends TestCase
{
  public function testActiveBranchEndsBeforeRollback(): void
  {
    $channel = new StrictXaRollbackChannel();
    $manager = $this->startBranch($channel);
    $manager->rollBack();
    self::assertSame(['START', 'END', 'ROLLBACK'], $channel->connection->commands);
    self::assertFalse($channel->connection->isActive);
    self::assertSame([$channel->connection], $channel->puts);
  }

  public function testDestructorRollsBackActiveBranch(): void
  {
    $channel = new StrictXaRollbackChannel();
    $manager = $this->startBranch($channel);
    unset($manager);
    self::assertSame(['START', 'END', 'ROLLBACK'], $channel->connection->commands);
    self::assertFalse($channel->connection->isActive);
  }

  public function testFailedRollbackDiscardsConnectionInsteadOfReturningIt(): void
  {
    $channel = new StrictXaRollbackChannel();
    $channel->connection->hasRollbackFailure = true;
    $manager = $this->startBranch($channel);
    ob_start();
    try {
      $manager->rollBack();
    } finally {
      ob_end_clean();
    }
    self::assertTrue($channel->connection->isClosed);
    self::assertSame([null], $channel->puts);
    self::assertSame(0, $manager->transactionLevel());
  }

  public function testFailedEndDiscardsStillActiveConnection(): void
  {
    $channel = new StrictXaRollbackChannel();
    $channel->connection->hasEndFailure = true;
    $manager = $this->startBranch($channel);
    $manager->rollBack();
    self::assertTrue($channel->connection->isClosed);
    self::assertSame([null], $channel->puts);
    self::assertNotContains('ROLLBACK', $channel->connection->commands);
  }

  public function testFailedPreparedRollbackPreservesJournal(): void
  {
    $channel = new StrictXaRollbackChannel();
    $journalChannel = new XaRecordingChannel();
    $manager = new ConnectManager();
    $manager->startXa(new XaJournal($journalChannel, 'unresolved_journal'));
    $connection = $manager->pop($channel, 'write');
    $context = new ReflectionProperty($manager, 'xaContext')->getValue($manager);
    $context->markBranch($connection, XaBranchState::Prepared);
    $context->setJournalRowExists(true);
    $connection->isActive = false;
    $connection->hasRollbackFailure = true;
    $manager->rollBack();
    self::assertSame(XaBranchState::Prepared, $context->branchStateOf($connection));
    self::assertTrue($context->journalRowExists());
    self::assertSame([], $journalChannel->log, '未决分支必须留存 journal 恢复意图');
  }

  public function testAlreadyRemovedBranchIsIdempotent(): void
  {
    $channel = new StrictXaRollbackChannel();
    $channel->connection->isAlreadyRemoved = true;
    $manager = $this->startBranch($channel);
    $manager->rollBack();
    self::assertSame([$channel->connection], $channel->puts);
    self::assertFalse($channel->connection->isClosed);
  }

  /**
   * 创建已借还过连接的活跃 XA 分支，模拟业务异常发生前的状态。
   * @param StrictXaRollbackChannel $channel 测试通道
   * @return ConnectManager 活跃事务管理器
   */
  private function startBranch(StrictXaRollbackChannel $channel): ConnectManager
  {
    $manager = new ConnectManager();
    $manager->startXa(new XaJournal(new XaRecordingChannel(), 'rollback_journal'));
    $connection = $manager->pop($channel, 'write');
    $manager->put($channel, $connection);
    return $manager;
  }
}

/** 严格连接持有者：用归还记录区分正常连接与淘汰连接。 */
final class StrictXaRollbackChannel extends XaRecordingChannel
{
  public StrictXaRollbackConnection $connection;

  public function __construct()
  {
    $this->connection = new StrictXaRollbackConnection($this);
  }

  public function pop(string $type): PDO
  {
    return $this->connection;
  }
}

/** PDO 状态机替身：真实 MySQL 不允许 ACTIVE 分支直接 XA ROLLBACK。 */
final class StrictXaRollbackConnection extends XaRecordingConnection
{
  /** @var list<string> XA 命令序列 */
  public array $commands = [];
  public bool $isActive = false;
  public bool $isClosed = false;
  public bool $hasRollbackFailure = false;
  public bool $hasEndFailure = false;
  public bool $isAlreadyRemoved = false;

  public function exec(string $statement): int|false
  {
    if (!preg_match('/^XA (START|END|ROLLBACK) /', $statement, $matches)) {
      return parent::exec($statement);
    }
    $command = $matches[1];
    $this->commands[] = $command;
    if ($command === 'END' && $this->hasEndFailure) {
      throw new PDOException('injected XA END failure', 1399);
    }
    if ($command === 'ROLLBACK' && $this->isAlreadyRemoved) {
      throw new PDOException('XAER_NOTA', 1397);
    }
    if ($command === 'ROLLBACK' && ($this->isActive || $this->hasRollbackFailure)) {
      throw new PDOException('XAER_RMFAIL: ACTIVE or injected rollback failure', 1399);
    }
    $this->isActive = $command === 'START';
    return parent::exec($statement);
  }

  /** 模拟驱动主动断开：坏连接不能再归还复用。 */
  public function close(): void
  {
    $this->isClosed = true;
    $this->isActive = false;
  }
}
