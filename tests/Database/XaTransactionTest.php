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
   * journal SELECT 返回按业务通道 recoverRows + journalState 推导的模拟行
   *
   * @param array<string,XaRecordingChannel> $channels 测试通道集合
   */
  private function runRecoveryWith(array $channels): void
  {
    $db = App::factory()->make(DbManager::class);
    foreach ($channels as $name => $channel) $db->addChannel($name, $channel);
    // 按业务通道的未决 xid 推导 journal 模拟行
    $journalChannel = $channels['xa_journal'];
    $rows = [];
    foreach ($channels as $channel) {
      if ($channel === $journalChannel) continue;
      foreach ($channel->recoverRows as $xid) {
        $gtrid = preg_replace('/-b\d+$/', '', $xid);
        $rows[$gtrid] = [
          'gtrid' => $gtrid,
          'state' => $channel->journalState,
          'branches' => '["' . $xid . '"]',
        ];
      }
    }
    $journalChannel->selectRows = array_values($rows);
    $prop = new ReflectionProperty(DbManager::class, 'channels');
    $origin = $prop->getValue($db);
    try {
      $prop->setValue($db, $channels);
      XaRecovery::run();
    } finally {
      $prop->setValue($db, $origin);
    }
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
