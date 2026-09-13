<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Exception;
use Override;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use function Swoole\Coroutine\run;
use Viswoole\Core\App;
use Viswoole\Database\Channel;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;
use Viswoole\Database\Transaction\XaCoordinator;
use Viswoole\Database\Transaction\XaJournal;
use Viswoole\Database\Transaction\XaRecovery;

/**
 * XA 两阶段提交测试
 *
 * 通过记录 SQL 序列的通道替身（XaRecordingChannel）验证：
 * 1. 提交协议时序：XA END → journal(prepare_start) → XA PREPARE → journal(prepared) → XA COMMIT → journal 清理；
 * 2. 复现"第二通道 XA COMMIT 失败"场景：journal 留存 prepared 行、失败分支停留未决（不盲目回滚），
 *    由恢复任务补齐 XA COMMIT 后删除 journal 行——修复前逐连接 commit 会造成部分提交；
 * 3. PREPARE 阶段失败全体 XA ROLLBACK + journal 删除（全量放弃）；
 * 4. 恢复任务：journal 行状态（prepare_start/prepared）决定 XA ROLLBACK/XA COMMIT，
 *    XAER_NOTA（已终结）被容忍保证幂等；
 * 5. 嵌套（XA 内 SAVEPOINT）、普通事务内禁止开启 XA、未收尾事务析构回滚。
 */
class XaTransactionTest extends TestCase
{
  protected function setUp(): void
  {
    App::factory()->make(DbManager::class)->setDebug(false);
    // journal 通道指向记录替身，避免真实 default 通道参与测试
    App::factory()->get('config')->set('database.xa.journal_channel', 'xa_journal');
    App::factory()->get('config')->set('database.xa.journal_table', 'jt');
  }

  protected function tearDown(): void
  {
    // 清理故障注入钩子，避免污染后续用例
    XaCoordinator::$faultInjector = null;
  }

  /**
   * 场景1：正常提交的两阶段协议时序
   */
  public function testTwoPhaseCommitProtocolSequence(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelB = new XaRecordingChannel();
    run(function () use ($journalChannel, $channelA, $channelB): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      $manager->pop($channelB, 'write');
      $manager->commit();
      self::assertSame(0, $manager->transactionLevel());
    });
    // 分支 xid 共享 gtrid 前缀且互不相同
    $startA = $this->firstSqlMatching($channelA->log, '/^XA START /');
    $startB = $this->firstSqlMatching($channelB->log, '/^XA START /');
    self::assertNotNull($startA);
    self::assertNotNull($startB);
    $gtrid = $this->extractGtrid($startA);
    self::assertNotSame($startA, $startB, '两个分支应使用不同 xid');
    self::assertSame("-b1'", substr($startA, -4), '首分支应为 b1');
    self::assertSame("-b2'", substr($startB, -4), '次分支应为 b2');
    // 每个分支完整执行 END → PREPARE → COMMIT
    self::assertNotNull($this->firstSqlMatching($channelA->log, "/^XA END '$gtrid-b1'/"));
    self::assertNotNull($this->firstSqlMatching($channelA->log, "/^XA PREPARE '$gtrid-b1'/"));
    self::assertNotNull($this->firstSqlMatching($channelA->log, "/^XA COMMIT '$gtrid-b1'/"));
    self::assertNotNull($this->firstSqlMatching($channelB->log, "/^XA END '$gtrid-b2'/"));
    self::assertNotNull($this->firstSqlMatching($channelB->log, "/^XA PREPARE '$gtrid-b2'/"));
    self::assertNotNull($this->firstSqlMatching($channelB->log, "/^XA COMMIT '$gtrid-b2'/"));
    // journal 生命周期：prepare_start 写入 → prepared 标记 → 清理
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringContainsString('INSERT INTO', $journalSql);
    self::assertStringContainsString('UPDATE', $journalSql);
    self::assertStringContainsString('DELETE FROM', $journalSql);
    // 连接全部归还连接池
    self::assertCount(1, $channelA->puts);
    self::assertCount(1, $channelB->puts);
  }

  /**
   * 场景2（复现测试）：第二分支 XA COMMIT 失败——不得静默回滚已 prepared 分支，
   * journal 留存 prepared 行交由恢复任务补齐，连接归还但事务在服务端保持未决
   */
  public function testCommitFailureOnSecondBranchKeepsInDoubtState(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelB = new XaRecordingChannel();
    // 注入：第二分支（索引 1）在 XA COMMIT 前失败
    XaCoordinator::$faultInjector = function (string $stage, int $branchIndex): void {
      if ($stage === 'commit' && $branchIndex === 1) {
        throw new RuntimeException('模拟第二通道 XA COMMIT 失败');
      }
    };
    run(function () use ($journalChannel, $channelA, $channelB): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      $manager->pop($channelB, 'write');
      try {
        $manager->commit();
        self::fail('提交失败应向外抛出异常');
      } catch (DbException) {
        // 预期异常：调用方感知失败，未决事务交由恢复任务收敛
      }
    });
    // 第一分支已 XA COMMIT（无法撤回），第二分支不得被回滚或提交
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA COMMIT /'));
    self::assertNull($this->firstSqlMatching($channelB->log, '/^XA COMMIT /'), '失败分支不应被继续提交');
    self::assertNull($this->firstSqlMatching($channelB->log, '/^XA ROLLBACK /'), '失败分支不应被静默回滚（已 prepared，须由恢复任务决策）');
    // journal 行留存 prepared 状态（未被 DELETE）
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringContainsString('UPDATE', $journalSql, '应已标记提交意图');
    self::assertStringNotContainsString('DELETE FROM', $journalSql, 'journal 行必须留存供恢复任务使用');
    // 连接仍被归还（prepared 事务由服务端持有，连接可复用）
    self::assertCount(1, $channelA->puts);
    self::assertCount(1, $channelB->puts);
  }

  /**
   * 场景3：PREPARE 阶段失败——全体 XA ROLLBACK、journal 行删除（全量放弃）
   */
  public function testPrepareFailureRollsBackAllBranches(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelB = new XaRecordingChannel();
    XaCoordinator::$faultInjector = function (string $stage, int $branchIndex): void {
      if ($stage === 'prepare' && $branchIndex === 1) {
        throw new RuntimeException('模拟第二通道 XA PREPARE 失败');
      }
    };
    run(function () use ($journalChannel, $channelA, $channelB): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      $manager->pop($channelB, 'write');
      try {
        $manager->commit();
        self::fail('PREPARE 失败应向外抛出异常');
      } catch (DbException) {
      }
    });
    // 已成功 PREPARE 的分支与失败分支均被 XA ROLLBACK
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA PREPARE /'));
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA ROLLBACK /'));
    self::assertNull($this->firstSqlMatching($channelB->log, '/^XA PREPARE /'));
    self::assertNotNull($this->firstSqlMatching($channelB->log, '/^XA ROLLBACK /'));
    // journal 行被清理（回滚后不再需要恢复）
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringNotContainsString('UPDATE', $journalSql, '未进入提交意图阶段');
    self::assertStringContainsString('DELETE FROM', $journalSql);
  }

  /**
   * 场景4：显式回滚——每个分支 XA ROLLBACK，未写过 journal 则不做 journal I/O
   */
  public function testRollBackIssuesXaRollbackPerBranch(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelB = new XaRecordingChannel();
    run(function () use ($journalChannel, $channelA, $channelB): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      $manager->pop($channelB, 'write');
      $manager->rollBack();
    });
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA ROLLBACK /'));
    self::assertNotNull($this->firstSqlMatching($channelB->log, '/^XA ROLLBACK /'));
    self::assertSame([], $journalChannel->log, '未尝试提交则不应有任何 journal 写入');
    self::assertCount(1, $channelA->puts);
    self::assertCount(1, $channelB->puts);
  }

  /**
   * 场景5：恢复任务——journal 行为 prepared 时对未决分支补齐 XA COMMIT 并清理行
   */
  public function testRecoveryCommitsInDoubtBranchesForPreparedRow(): void
  {
    $gtrid = 'vw' . str_repeat('ab', 8);
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    // 模拟服务端 XA RECOVER 返回一个未决分支（裸 hex 格式 data 列）
    $channelA->recoverRows = ["{$gtrid}-b1"];
    $channelA->journalState = XaJournal::STATE_PREPARED;
    $this->runRecoveryWith(['xa_journal' => $journalChannel, 'xa_a' => $channelA]);
    // 未决分支被 XA COMMIT
    self::assertNotNull(
      $this->firstSqlMatching($channelA->log, "/^XA COMMIT '$gtrid-b1'/"),
      '恢复任务应对未决分支执行 XA COMMIT'
    );
    // journal 通道收到 SELECT 与 DELETE（行已清理）
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringContainsString('SELECT', $journalSql);
    self::assertStringContainsString('DELETE FROM', $journalSql);
    // XA RECOVER 语句精确断言（真实 MySQL 仅接受 CONVERT XID，
    // 曾误写 CONVERT INTO 导致单测 mock 未暴露、真机恢复全挂）
    self::assertNotNull(
      $this->firstSqlMatching($channelA->log, '/^XA RECOVER CONVERT XID$/'),
      'XA RECOVER 必须使用 CONVERT XID 语法（MySQL 官方语法）'
    );
  }

  /**
   * 场景6：恢复任务——journal 行为 prepare_start 时对未决分支 XA ROLLBACK（含 0x 前缀 hex data）
   */
  public function testRecoveryRollsBackInDoubtBranchesForPrepareStartRow(): void
  {
    $gtrid = 'vw' . str_repeat('cd', 8);
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelA->recoverRows = ["{$gtrid}-b1"];
    $channelA->recoverHexPrefix = '0x';
    $channelA->journalState = XaJournal::STATE_PREPARE_START;
    $this->runRecoveryWith(['xa_journal' => $journalChannel, 'xa_a' => $channelA]);
    self::assertNotNull(
      $this->firstSqlMatching($channelA->log, "/^XA ROLLBACK '$gtrid-b1'/"),
      'prepare_start 行的未决分支应被 XA ROLLBACK'
    );
  }

  /**
   * 场景7：普通事务内禁止开启 XA 事务（无法中途升级，fail-fast）
   */
  public function testStartXaInsideNormalTransactionThrows(): void
  {
    $channel = new XaRecordingChannel();
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->pop($channel, 'write');
      try {
        $manager->startXa(new XaJournal(new XaRecordingChannel(), 'jt'));
        self::fail('普通事务内开启 XA 应抛出异常');
      } catch (DbException $e) {
        self::assertStringContainsString('无法开启 XA 事务', $e->getMessage());
      }
      $manager->rollBack();
    });
  }

  /**
   * 场景8：XA 事务内嵌套普通事务——内层走 SAVEPOINT，外层 2PC 收尾
   */
  public function testNestedTransactionInsideXaUsesSavepoints(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    run(function () use ($journalChannel, $channelA): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      $manager->start();
      self::assertSame(2, $manager->transactionLevel());
      $manager->commit();
      self::assertSame(1, $manager->transactionLevel());
      $manager->commit();
      self::assertSame(0, $manager->transactionLevel());
    });
    $aSql = implode(';', $channelA->log);
    self::assertStringContainsString('SAVEPOINT viswoole_sp_2', $aSql);
    self::assertStringContainsString('RELEASE SAVEPOINT viswoole_sp_2', $aSql);
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA COMMIT /'));
  }

  /**
   * 场景9：未收尾的 XA 事务——协程结束析构时对各活跃分支 XA ROLLBACK 并归还连接
   */
  public function testUnfinishedXaTransactionRolledBackOnDestruct(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    run(function () use ($journalChannel, $channelA): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      // 不收尾，交给析构兜底
    });
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA ROLLBACK /'));
    self::assertCount(1, $channelA->puts);
    self::assertSame([], $journalChannel->log, '未尝试提交则无 journal 写入');
  }

  /**
   * 场景10：DbManager::startXaTransaction 闭包形式——正常路径透传返回值
   */
  public function testDbManagerStartXaTransactionClosureForm(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $this->registerChannels(['xa_journal' => $journalChannel, 'xa_a' => $channelA]);
    $result = null;
    run(function () use ($channelA, &$result): void {
      $db = App::factory()->make(DbManager::class);
      $result = $db->startXaTransaction(function () use ($channelA): string {
        $channelA->execute('INSERT INTO t (v) VALUES (?)', ['a']);
        return 'ok';
      });
    });
    self::assertSame('ok', $result);
    // 业务 SQL 在 XA START 之后、XA END 之前执行
    $aSql = implode(';', $channelA->log);
    self::assertStringContainsString('INSERT INTO t', $aSql);
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA COMMIT /'));
  }

  /**
   * 场景11（H1 复现）：恢复任务对"年轻的 prepare_start 行"（尚在提交进行中）
   * 不得处置——须过冷却期，防止误删活跃事务的 journal 行或误回滚其分支
   */
  public function testRecoverySkipsYoungPrepareStartRows(): void
  {
    $gtrid = 'vw' . str_repeat('ef', 8);
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    // 该事务正提交到一半：journal 行为 prepare_start 且刚写入，分支已 prepared
    // 但 XA RECOVER 尚未返回（或返回后本通道扫描）——模拟恢复任务在此刻运行
    $channelA->recoverRows = ["{$gtrid}-b1"];
    $channelA->journalState = XaJournal::STATE_PREPARE_START;
    $this->runRecoveryWith(['xa_journal' => $journalChannel, 'xa_a' => $channelA], youngRow: true);
    // 不对未决分支执行任何终结语句（避免误回滚活跃事务的已 prepared 分支）
    self::assertNull(
      $this->firstSqlMatching($channelA->log, "/^XA (COMMIT|ROLLBACK) '$gtrid-b1'/"),
      '冷却期内的 prepare_start 行不应被处置'
    );
    // journal 行不被删除（误删后崩溃将丢失恢复依据）
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringNotContainsString('DELETE FROM', $journalSql, '冷却期内的 journal 行不应被删除');
  }

  /**
   * 场景12（H2 复现）：XA START 失败（如引擎不支持）时连接须归还通道而非泄漏
   */
  public function testFailedXaStartReturnsConnectionToChannel(): void
  {
    $channel = new class () extends XaRecordingChannel {
      public array $brokenConnections = [];

      #[\Override]
      public function pop(string $type): \PDO
      {
        // 返回一个 XA START 必然失败的连接（记录日志时抛出，模拟执行失败）
        $broken = new class ($this) extends \PDO {
          public function __construct(private readonly XaRecordingChannel $owner)
          {
            parent::__construct('sqlite::memory:');
          }

          #[\Override]
          public function exec(string $statement): int|false
          {
            // 首条 XA START 模拟存储引擎不支持而失败
            if (str_starts_with($statement, 'XA START')) {
              throw new \PDOException('XA not supported by storage engine');
            }
            return parent::exec($statement);
          }
        };
        $this->brokenConnections[] = $broken;
        return $broken;
      }
    };
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal(new XaRecordingChannel(), 'jt'));
      try {
        $manager->pop($channel, 'write');
        self::fail('XA START 失败应向外抛出异常');
      } catch (DbException) {
      }
    });
    self::assertCount(1, $channel->puts, 'XA START 失败的连接必须归还通道，不得泄漏');
  }

  /**
   * 场景13（H3 复现）：XaJournal 写方法拒绝含引号/空白等危险字符的 gtrid（注入防御）
   */
  public function testJournalRejectsMalformedGtrid(): void
  {
    $journal = new XaJournal(new XaRecordingChannel(), 'jt');
    $malformed = ["vw' OR '1'='1", "vw; DROP TABLE t--", "vw\nabc", ''];
    $rejected = 0;
    foreach ($malformed as $gtrid) {
      foreach (
        [
          fn() => $journal->recordPrepareStart($gtrid, []),
          fn() => $journal->markPrepared($gtrid),
          fn() => $journal->remove($gtrid),
        ] as $write
      ) {
        try {
          $write();
          self::fail("非法 gtrid（{$gtrid}）应被拒绝");
        } catch (DbException) {
          $rejected++;
        }
      }
    }
    self::assertSame(count($malformed) * 3, $rejected, '全部非法 gtrid 均应被三个写方法拒绝');
  }

  /**
   * 场景14（H6 复现）：无任何分支（未执行 SQL）的 XA 事务提交不产生 journal 写入
   */
  public function testEmptyBranchesCommitSkipsJournalIo(): void
  {
    $journalChannel = new XaRecordingChannel();
    run(function () use ($journalChannel): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      // 未执行任何 SQL——无分支
      $manager->commit();
      self::assertSame(0, $manager->transactionLevel());
    });
    // 建表探测可以有，但不得有 INSERT/UPDATE/DELETE 三连写
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringNotContainsString('INSERT INTO', $journalSql, '空分支事务不应写 journal 行');
    self::assertStringNotContainsString('UPDATE', $journalSql);
    self::assertStringNotContainsString('DELETE FROM', $journalSql);
  }

  /**
   * 场景15（遗留①复现）：普通事务 BEGIN 失败（连接失效）时连接须归还通道而非泄漏
   */
  public function testBeginTransactionFailureReturnsConnectionToChannel(): void
  {
    $channel = new class () extends XaRecordingChannel {
      #[\Override]
      public function pop(string $type): \PDO
      {
        return new class () extends \PDO {
          public function __construct()
          {
            parent::__construct('sqlite::memory:');
          }

          #[\Override]
          public function beginTransaction(): bool
          {
            throw new \PDOException('MySQL server has gone away');
          }
        };
      }
    };
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      try {
        $manager->pop($channel, 'write');
        self::fail('BEGIN 失败应向外抛出异常');
      } catch (\PDOException) {
      }
      $manager->rollBack();
    });
    self::assertCount(1, $channel->puts, 'BEGIN 失败的连接必须归还通道，不得泄漏');
  }

  /**
   * 场景16（遗留①复现）：BEGIN 成功但保存点补齐失败的中间态——
   * 连接归还前必须回滚已开启的事务，避免带事务状态回池污染其他协程
   */
  public function testSavepointFailureRollsBackAndReturnsConnection(): void
  {
    $channel = new class () extends XaRecordingChannel {
      #[\Override]
      public function pop(string $type): \PDO
      {
        return new class () extends \PDO {
          public bool $rolledBack = false;

          public function __construct()
          {
            parent::__construct('sqlite::memory:');
          }

          #[\Override]
          public function exec(string $statement): int|false
          {
            if (str_starts_with($statement, 'SAVEPOINT')) {
              throw new \PDOException('savepoint failed');
            }
            return parent::exec($statement);
          }

          #[\Override]
          public function rollBack(): bool
          {
            $this->rolledBack = true;
            return parent::rollBack();
          }
        };
      }
    };
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();                                     // L1
      $manager->start();                                     // L2：pop 时需补保存点
      try {
        $manager->pop($channel, 'write');
        self::fail('保存点失败应向外抛出异常');
      } catch (\PDOException) {
      }
      $manager->rollBack();
      $manager->rollBack();
    });
    self::assertCount(1, $channel->puts, '保存点失败的连接必须归还通道，不得泄漏');
    self::assertTrue($channel->puts[0]->rolledBack, '归还前应回滚已开启的事务（BEGIN 成功的中间态）');
  }

  /**
   * 场景17（遗留②复现）：恢复任务跳过非 MySQL PDO 通道——
   * XA START 在 SQLite 等数据库上必然失败、分支从未建立，扫描只会产生
   * 告警噪音并永久阻塞 journal 行清理
   */
  public function testRecoverySkipsNonMysqlPdoChannels(): void
  {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $gtrid = 'vw' . str_repeat('12', 8);
    $journalChannel = new XaRecordingChannel();
    $tmpDb = sys_get_temp_dir() . '/viswoole_xa_skip_' . uniqid() . '.sqlite';
    (new \PDO('sqlite:' . $tmpDb))->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
    $sqliteChannel = new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
    $this->runRecoveryWith(
      ['xa_journal' => $journalChannel, 'xa_sqlite' => $sqliteChannel],
      journalRows: [[
        'gtrid' => $gtrid,
        'state' => XaJournal::STATE_PREPARED,
        'branches' => '["' . $gtrid . '-b1"]',
        'created_at' => '2020-01-01 00:00:00',
      ]]
    );
    // 修复前：SQLite 通道被扫描 → XA RECOVER 语法错误 → journal 行被阻塞（无 DELETE）；
    // 修复后：非 MySQL PDO 通道被跳过 → 行正常清理
    self::assertStringContainsString(
      'DELETE FROM',
      implode(';', $journalChannel->log),
      '非 MySQL PDO 通道应被跳过，不得阻塞 journal 行清理'
    );
    @unlink($tmpDb);
  }

  /**
   * 场景18（R1 复现）：并发恢复已按 journal 意图终结分支后，原事务的 XA COMMIT
   * 得到 XAER_NOTA——提交侧须容忍（与恢复侧 recoverXid 对称），不得抛出
   * "停留未决"异常导致调用方误判失败后重试业务造成重复数据
   */
  public function testConcurrentlyRecoveredBranchToleratesNotExists(): void
  {
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelB = new XaRecordingChannel();
    // 注入：第二分支（索引 1）的 XA COMMIT 得到 XAER_NOTA
    // （模拟恢复任务已抢先按 journal prepared 意图终结该分支）
    XaCoordinator::$faultInjector = function (string $stage, int $branchIndex): void {
      if ($stage === 'commit' && $branchIndex === 1) {
        throw new RuntimeException('SQLSTATE[HY000] [1390] XAER_NOTA: Unknown XID');
      }
    };
    run(function () use ($journalChannel, $channelA, $channelB): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal($journalChannel, 'jt'));
      $manager->pop($channelA, 'write');
      $manager->pop($channelB, 'write');
      $thrown = null;
      try {
        $manager->commit();
      } catch (DbException $e) {
        $thrown = $e;
      }
      self::assertNull($thrown, 'XAER_NOTA（分支已被并发恢复终结）应被容忍，不得抛异常');
      self::assertSame(0, $manager->transactionLevel());
    });
    // 提交语义完整达成：A 正常 XA COMMIT，B 走 PREPARE（终结由并发恢复完成）
    self::assertNotNull($this->firstSqlMatching($channelA->log, '/^XA COMMIT /'));
    self::assertNotNull($this->firstSqlMatching($channelB->log, '/^XA PREPARE /'));
    // journal 行正常清理（事务已收敛，无未决残留）
    self::assertStringContainsString('DELETE FROM', implode(';', $journalChannel->log));
    // 连接全部归还（Done 分支不经 XA ROLLBACK 直接回池）
    self::assertCount(1, $channelA->puts);
    self::assertCount(1, $channelB->puts);
    self::assertNull($this->firstSqlMatching($channelB->log, '/^XA ROLLBACK /'));
  }

  /**
   * 场景19（dry-run）：只扫描并报告将执行的动作，不实际终结分支、不删 journal 行
   */
  public function testRecoveryDryRunMakesNoChanges(): void
  {
    $gtrid = 'vw' . str_repeat('77', 8);
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $channelA->recoverRows = ["{$gtrid}-b1"];
    $channelA->journalState = XaJournal::STATE_PREPARED;
    $this->runRecoveryWith(
      ['xa_journal' => $journalChannel, 'xa_a' => $channelA],
      journalRows: [[
        'gtrid' => $gtrid,
        'state' => XaJournal::STATE_PREPARED,
        'branches' => '["' . $gtrid . '-b1"]',
        'created_at' => '2020-01-01 00:00:00',
      ]],
      dryRun: true
    );
    // 分支不被终结、journal 行不被删除——一切状态保持原样
    self::assertNull($this->firstSqlMatching($channelA->log, '/^XA (COMMIT|ROLLBACK) /'), 'dry-run 不得终结分支');
    self::assertStringNotContainsString('DELETE FROM', implode(';', $journalChannel->log), 'dry-run 不得删除 journal 行');
    // 但探测扫描照常执行（dry-run 的价值在于预览将处置什么）
    self::assertStringContainsString('XA RECOVER', implode(';', $channelA->log));
  }

  /**
   * 场景20（xid 过滤）：仅处置指定 gtrid 的 journal 行与未决分支，
   * 其余 gtrid 的行与分支保持原样
   */
  public function testRecoveryWithXidFilterOnlyTouchesMatchingRow(): void
  {
    $gtridTarget = 'vw' . str_repeat('aa', 8);
    $gtridOther = 'vw' . str_repeat('bb', 8);
    $journalChannel = new XaRecordingChannel();
    $channelTarget = new XaRecordingChannel();
    $channelOther = new XaRecordingChannel();
    $channelTarget->recoverRows = ["{$gtridTarget}-b1"];
    $channelOther->recoverRows = ["{$gtridOther}-b1"];
    $this->runRecoveryWith(
      ['xa_journal' => $journalChannel, 'xa_target' => $channelTarget, 'xa_other' => $channelOther],
      journalRows: [
        [
          'gtrid' => $gtridTarget,
          'state' => XaJournal::STATE_PREPARED,
          'branches' => '["' . $gtridTarget . '-b1"]',
          'created_at' => '2020-01-01 00:00:00',
        ],
        [
          'gtrid' => $gtridOther,
          'state' => XaJournal::STATE_PREPARED,
          'branches' => '["' . $gtridOther . '-b1"]',
          'created_at' => '2020-01-01 00:00:00',
        ],
      ],
      onlyGtrid: $gtridTarget
    );
    // 目标分支被 XA COMMIT、目标行被删除
    self::assertNotNull($this->firstSqlMatching($channelTarget->log, '/^XA COMMIT /'));
    self::assertStringContainsString("DELETE FROM", implode(';', $journalChannel->log));
    self::assertStringContainsString($gtridTarget, implode(';', $journalChannel->log));
    // 其他 gtrid 的分支不动、行不删
    self::assertNull($this->firstSqlMatching($channelOther->log, '/^XA (COMMIT|ROLLBACK) /'), '未过滤命中的分支不得被处置');
    self::assertStringNotContainsString("'$gtridOther'", implode(';', $journalChannel->log), '未过滤命中的行不得被删除');
  }

  /**
   * 场景20b（提示语义区分）：--xid 过滤时范围外悬挂事务的提示必须是
   * "已过滤跳过"（journal 有记录但不在本次范围），而非"无 journal 记录"
   */
  public function testXidFilterReportsSkippedInsteadOfMissing(): void
  {
    $gtridTarget = 'vw' . str_repeat('aa', 8);
    $gtridOther = 'vw' . str_repeat('bb', 8);
    $journalChannel = new XaRecordingChannel();
    $channelTarget = new XaRecordingChannel();
    $channelOther = new XaRecordingChannel();
    $channelTarget->recoverRows = ["{$gtridTarget}-b1"];
    $channelOther->recoverRows = ["{$gtridOther}-b1"];
    $output = $this->runRecoveryWith(
      ['xa_journal' => $journalChannel, 'xa_target' => $channelTarget, 'xa_other' => $channelOther],
      journalRows: [
        [
          'gtrid' => $gtridTarget,
          'state' => XaJournal::STATE_PREPARED,
          'branches' => '["' . $gtridTarget . '-b1"]',
          'created_at' => '2020-01-01 00:00:00',
        ],
        [
          'gtrid' => $gtridOther,
          'state' => XaJournal::STATE_PREPARED,
          'branches' => '["' . $gtridOther . '-b1"]',
          'created_at' => '2020-01-01 00:00:00',
        ],
      ],
      onlyGtrid: $gtridTarget
    );
    self::assertStringContainsString('已过滤跳过', $output, '范围外悬挂应提示"已过滤跳过"');
    self::assertStringContainsString($gtridOther, $output, '提示应指明被跳过事务的 gtrid');
    self::assertStringNotContainsString('无 journal 记录', $output, '有记录的悬挂不得误报为"无 journal 记录"');
  }

  /**
   * 场景22（基线锁定）：全量恢复时，真正无 journal 记录的悬挂事务
   * 报"无 journal 记录请人工处理"（journal 需有其他行才会触发通道扫描）
   */
  public function testUnrecordedInDoubtStillReportsMissingJournal(): void
  {
    $gtridKnown = 'vw' . str_repeat('aa', 8);
    $gtridMissing = 'vw' . str_repeat('cc', 8);
    $journalChannel = new XaRecordingChannel();
    $channelKnown = new XaRecordingChannel();
    $channelMissing = new XaRecordingChannel();
    $channelKnown->recoverRows = ["{$gtridKnown}-b1"];
    $channelMissing->recoverRows = ["{$gtridMissing}-b1"];
    $output = $this->runRecoveryWith(
      ['xa_journal' => $journalChannel, 'xa_known' => $channelKnown, 'xa_missing' => $channelMissing],
      journalRows: [
        [
          'gtrid' => $gtridKnown,
          'state' => XaJournal::STATE_PREPARED,
          'branches' => '["' . $gtridKnown . '-b1"]',
          'created_at' => '2020-01-01 00:00:00',
        ],
      ]
    );
    // 有记录的分支被正常处置（证明扫描已发生）
    self::assertNotNull($this->firstSqlMatching($channelKnown->log, '/^XA COMMIT /'));
    self::assertStringContainsString('无 journal 记录', $output, '无记录悬挂应提示人工处理');
    self::assertStringContainsString($gtridMissing, $output, '提示应包含悬挂事务的 xid');
    self::assertNull($this->firstSqlMatching($channelMissing->log, '/^XA (COMMIT|ROLLBACK) /'), '无记录悬挂不得被处置');
  }

  /**
   * 场景21：非 MySQL 的 PDO 通道参与 XA——开启瞬间抛出明确异常（fail-fast），
   * 而非数据库底层"syntax error at or near XA"；连接归还无泄漏
   */
  public function testNonMysqlPdoChannelRejectsXaWithClearError(): void
  {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_xa_pg_' . uniqid() . '.sqlite';
    (new \PDO('sqlite:' . $tmpDb))->exec('CREATE TABLE t (id INTEGER)');
    $sqliteChannel = new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
    run(function () use ($sqliteChannel, $tmpDb): void {
      $manager = ConnectManager::factory();
      $manager->startXa(new XaJournal(new XaRecordingChannel(), 'jt'));
      try {
        $manager->pop($sqliteChannel, 'write');
        self::fail('非 MySQL PDO 通道参与 XA 应抛出明确异常');
      } catch (DbException $e) {
        self::assertStringContainsString('不支持 XA 事务', $e->getMessage());
        self::assertStringContainsString('sqlite', $e->getMessage());
        self::assertStringContainsString('MySQL', $e->getMessage());
      }
      $manager->rollBack();
    });
    @unlink($tmpDb);
  }

  /* ------------------------------------------------------------------ */
  /* 辅助方法                                                            */
  /* ------------------------------------------------------------------ */

  /**
   * 从日志中查找首条匹配的 SQL
   *
   * @param string[] $log SQL 日志
   * @param string $pattern 正则
   */
  private function firstSqlMatching(array $log, string $pattern): ?string
  {
    foreach ($log as $sql) {
      if (preg_match($pattern, $sql) === 1) return $sql;
    }
    return null;
  }

  /**
   * 从 XA START 语句中提取 gtrid（去掉分支后缀）
   */
  private function extractGtrid(string $xaStartSql): string
  {
    if (preg_match('/^XA START \'([^\']+)\'$/', $xaStartSql, $m) !== 1) {
      self::fail("无法解析 XA START 语句：{$xaStartSql}");
    }
    return preg_replace('/-b\d+$/', '', $m[1]);
  }

  /**
   * 注册测试通道到 DbManager
   *
   * @param array<string,Channel> $channels
   */
  private function registerChannels(array $channels): void
  {
    $db = App::factory()->make(DbManager::class);
    foreach ($channels as $name => $channel) $db->addChannel($name, $channel);
  }

  /**
   * 在隔离的通道集合下执行恢复任务（恢复任务遍历全部通道，需排除真实 default 通道），
   * journal SELECT 返回按业务通道 recoverRows + journalState 推导的模拟行，
   * 或直接使用显式传入的 journalRows
   *
   * @param array<string,XaRecordingChannel> $channels 测试通道集合
   * @param bool $youngRow 模拟 journal 行是否处于冷却期内（created_at 为当前时间）
   * @param array<int,array<string,mixed>>|null $journalRows 显式 journal 模拟行，优先于自动推导
   * @param string|null $onlyGtrid 仅处置指定 gtrid 的行（模拟 xa:recover --xid）
   * @param bool $dryRun 仅预览不执行（模拟 xa:recover --dry-run）
   * @return string 恢复任务的日志输出（供断言提示文案）
   */
  private function runRecoveryWith(
    array $channels,
    bool   $youngRow = false,
    ?array $journalRows = null,
    ?string $onlyGtrid = null,
    bool   $dryRun = false
  ): string
  {
    $db = App::factory()->make(DbManager::class);
    foreach ($channels as $name => $channel) $db->addChannel($name, $channel);
    $journalChannel = $channels['xa_journal'];
    if ($journalRows !== null) {
      $journalChannel->selectRows = $journalRows;
    } else {
      // 按业务通道的未决 xid 推导 journal 模拟行
      $rows = [];
      foreach ($channels as $channel) {
        if ($channel === $journalChannel) continue;
        foreach ($channel->recoverRows as $xid) {
          $gtrid = preg_replace('/-b\d+$/', '', $xid);
          $rows[$gtrid] = [
            'gtrid' => $gtrid,
            'state' => $channel->journalState,
            'branches' => '["' . $xid . '"]',
            // 冷却期内 = created_at 为现在；冷却期外 = 足够久远的过去
            'created_at' => $youngRow ? date('Y-m-d H:i:s') : '2020-01-01 00:00:00',
          ];
        }
      }
      $journalChannel->selectRows = array_values($rows);
    }
    $prop = new ReflectionProperty(DbManager::class, 'channels');
    $origin = $prop->getValue($db);
    try {
      $prop->setValue($db, $channels);
      // 恢复任务的 echo_log 告警输出会污染 PHPUnit 输出，捕获后返回供断言
      ob_start();
      try {
        XaRecovery::run($onlyGtrid, $dryRun);
      } finally {
        $output = ob_get_clean();
      }
    } finally {
      $prop->setValue($db, $origin);
    }
    return $output ?? '';
  }
}

/**
 * 记录 SQL 序列的通道替身
 *
 * pop 返回真实 PDO 子类（内存 SQLite）以通过 PDO 分派，
 * exec 全部记录后短路；query 对 XA RECOVER / SELECT 返回承载模拟行的真实语句。
 */
class XaRecordingChannel extends Channel
{
  /** @var string[] 记录的 SQL 序列 */
  public array $log = [];
  /** @var PDO[] 归还记录 */
  public array $puts = [];
  /** @var string[] 模拟 XA RECOVER 返回的未决 xid 列表 */
  public array $recoverRows = [];
  /** @var string 模拟 data 列格式：'' 裸 hex、'0x' 前缀 hex */
  public string $recoverHexPrefix = '';
  /** @var array<int,array<string,mixed>> 模拟 SELECT 返回的关联行 */
  public array $selectRows = [];
  /** @var int 模拟 journal 行状态（1=prepare_start 2=prepared） */
  public int $journalState = 2;

  #[Override]
  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): \Swoole\Database\PDOStatementProxy|PDOStatement|int|string
  {
    // 模拟 PDOChannel::execute 的事务接入行为：事务期间经 ConnectManager pop/put
    $manager = ConnectManager::factory();
    $connect = $manager->pop($this, 'write');
    try {
      $this->log[] = ($sql instanceof Raw ? $sql->sql : $sql);
    } finally {
      $manager->put($this, $connect);
    }
    return 0;
  }

  #[Override]
  public function pop(string $type): PDO
  {
    return new XaRecordingConnection($this);
  }

  #[Override]
  public function put(mixed $connect): void
  {
    $this->puts[] = $connect;
  }

  #[Override]
  public function build(Options $options): Raw
  {
    return new Raw('');
  }
}

/**
 * 记录 SQL 的 PDO 替身连接（内存 SQLite）
 *
 * exec 记录后短路返回；query 将 XA RECOVER / SELECT 映射为内存表上的真实查询，
 * 以返回可 fetchAll 的真实 PDOStatement（PDOStatement 无法直接实例化）。
 */
class XaRecordingConnection extends PDO
{
  public function __construct(private readonly XaRecordingChannel $owner)
  {
    parent::__construct('sqlite::memory:');
  }

  #[Override]
  public function exec(string $statement): int|false
  {
    $this->owner->log[] = $statement;
    return 1;
  }

  #[Override]
  public function query(
    string        $sql,
    ?int          $fetchMode = null,
    mixed         ...$fetchModeArgs
  ): PDOStatement|false
  {
    $this->owner->log[] = $sql;
    if (preg_match('/^\s*XA\s+RECOVER/i', $sql) === 1) {
      return $this->statementFor(['formatID', 'gtrid_length', 'bqual_length', 'data'], array_map(
        fn(string $xid): array => [
          'formatID' => 1,
          'gtrid_length' => strlen($xid),
          'bqual_length' => 0,
          'data' => $this->owner->recoverHexPrefix . bin2hex($xid),
        ],
        $this->owner->recoverRows
      ));
    }
    if (preg_match('/^\s*SELECT/i', $sql) === 1 && $this->owner->selectRows !== []) {
      $columns = array_keys($this->owner->selectRows[0]);
      return $this->statementFor($columns, $this->owner->selectRows);
    }
    return parent::query('SELECT 1');
  }

  /**
   * 用内存表承载模拟结果集并返回真实查询语句
   *
   * @param string[] $columns 列名
   * @param array<int,array<string,mixed>> $rows 模拟行
   */
  private function statementFor(array $columns, array $rows): PDOStatement
  {
    parent::exec('DROP TABLE IF EXISTS mock_result');
    $definitions = implode(', ', array_map(fn(string $c): string => "\"$c\"", $columns));
    parent::exec("CREATE TABLE mock_result ($definitions)");
    foreach ($rows as $row) {
      $values = implode(', ', array_map(
        fn(mixed $v): string => is_int($v) ? (string)$v : parent::quote((string)$v),
        array_values($row)
      ));
      parent::exec("INSERT INTO mock_result VALUES ($values)");
    }
    $select = implode(', ', array_map(fn(string $c): string => "\"$c\"", $columns));
    return parent::query("SELECT $select FROM mock_result");
  }
}
