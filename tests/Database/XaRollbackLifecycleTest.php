<?php

declare(strict_types=1);

/** XA 回滚生命周期回归：模拟 MySQL ACTIVE 约束及回滚故障，禁止脏连接归池。 */

namespace Viswoole\Tests\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Closure;
use Viswoole\Core\App;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Transaction\XaBranchState;
use Viswoole\Database\Transaction\XaJournal;
use Viswoole\Log\Drive;
use Viswoole\Log\Drives\File;
use Viswoole\Log\LogManager;

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
    ob_start();
    try {
      $manager->rollBack();
    } finally {
      ob_end_clean();
    }
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
    ob_start();
    try {
      $manager->rollBack();
    } finally {
      ob_end_clean();
    }
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
   * 日志驱动故障不得打断回滚清理：三个分支全部回滚失败且日志通道损坏时，
   * 仍必须逐一淘汰连接并重置事务层级，不得残留 ACTIVE 分支
   */
  public function testLogFailureDoesNotBlockBranchCleanup(): void
  {
    $restoreLog = self::breakDefaultLogChannel();
    $channels = [];
    for ($i = 0; $i < 3; $i++) {
      $channel = new StrictXaRollbackChannel();
      $channel->connection->hasRollbackFailure = true;
      $channels[] = $channel;
    }
    $manager = $this->startBranches($channels);
    ob_start();
    try {
      $manager->rollBack();
    } finally {
      ob_end_clean();
      $restoreLog();
    }
    foreach ($channels as $channel) {
      self::assertTrue($channel->connection->isClosed, '回滚失败的分支必须被淘汰');
      self::assertSame([null], $channel->puts, '淘汰连接必须以 put(null) 归还容量');
    }
    self::assertSame(0, $manager->transactionLevel(), '清理完成后事务层级必须归零');
  }

  /**
   * 日志驱动故障不得顶替原始异常：prepareAll 失败后 abortAll 的日志写入抛异常时，
   * 对外抛出的必须仍是描述 XA 提交失败的 DbException，且失败分支被淘汰、
   * 未失败分支被正常回滚归还
   */
  public function testLogFailurePreservesOriginalCommitError(): void
  {
    $restoreLog = self::breakDefaultLogChannel();
    $faulty = new StrictXaRollbackChannel();
    $healthy = new StrictXaRollbackChannel();
    $faulty->connection->hasEndFailure = true;
    $manager = $this->startBranches([$faulty, $healthy]);
    try {
      $manager->commit();
      self::fail('prepareAll 失败必须对外抛出 DbException');
    } catch (DbException $e) {
      self::assertStringContainsString('XA 事务提交失败', $e->getMessage(), '对外异常必须保留 XA 提交失败语义');
      self::assertStringContainsString('无恢复日志', $e->getMessage(), 'journal 未写入时不得虚报恢复日志');
      // XaDriver 会把驱动层 PDOException 包装为 DbException（原始异常挂在
      // 它的 previous 上），此处只需证明异常链未被日志故障替换
      self::assertInstanceOf(DbException::class, $e->getPrevious(), '原始驱动异常必须作为 previous 保留');
      self::assertStringContainsString('XA END', (string)$e->getPrevious()?->getMessage(), '异常链必须指向 END 故障');
    } finally {
      $restoreLog();
    }
    self::assertTrue($faulty->connection->isClosed, '回滚失败的分支必须被淘汰');
    self::assertSame([null], $faulty->puts);
    self::assertSame(['START', 'END', 'ROLLBACK'], $healthy->connection->commands, '健康分支必须正常回滚');
    self::assertSame([$healthy->connection], $healthy->puts, '健康分支必须归还复用');
    self::assertSame(0, $manager->transactionLevel());
  }

  /**
   * 创建包含多个分支的活跃 XA 事务（每通道一个分支，均已借还）
   * @param array<StrictXaRollbackChannel> $channels 分支通道（数组顺序即分支序号）
   * @return ConnectManager 活跃事务管理器
   */
  private function startBranches(array $channels): ConnectManager
  {
    $manager = new ConnectManager();
    $manager->startXa(new XaJournal(new XaRecordingChannel(), 'multi_branch_journal'));
    foreach ($channels as $channel) {
      $connection = $manager->pop($channel, 'write');
      $manager->put($channel, $connection);
    }
    return $manager;
  }

  /**
   * 临时将默认日志通道替换为必然失败的驱动，用于注入日志写入故障
   * @return Closure 调用后恢复原默认通道并还原 error_log 目标
   */
  private static function breakDefaultLogChannel(): Closure
  {
    $log = App::factory()->make(LogManager::class);
    $log->addChannel('file', new class extends Drive {
      public function save(array $logRecords): void
      {
        throw new RuntimeException('injected log driver failure');
      }
    });
    // 注入期间 DbLog 会降级 error_log 留痕，默认写 stderr 会污染套件输出：
    // 临时重定向到临时文件，恢复通道时一并还原并清理
    $originalTarget = (string)ini_get('error_log');
    $sink = (string)tempnam(sys_get_temp_dir(), 'vw_log_sink_');
    ini_set('error_log', $sink);
    return static function () use ($log, $originalTarget, $sink): void {
      ini_set('error_log', $originalTarget);
      $log->addChannel('file', File::class);
      is_file($sink) && unlink($sink);
    };
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
