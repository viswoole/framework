<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Transaction\XaRecovery;
use function Swoole\Coroutine\run;

/**
 * 非协程环境事务守护测试
 *
 * 锁定事务体系在非协程上下文（CLI 脚本、xa:recover 命令、workerStart 钩子）
 * 下的行为基线，防止未来改动（Swoole 升级、Context 重构、连接池调整）
 * 静默破坏非协程支持。
 *
 * 正确性的两个支柱（缺一即红）：
 * 1. ConnectionPool 非协程适配：pop 直接 createConnection、put 直接 closeConnection；
 * 2. 事务连接复用发生在 ConnectManager::$connections 数组层，不依赖池的协程语义。
 *
 * 注意：非协程下 ConnectManager::factory() 是进程级单例（Swoole 对 cid=-1
 * 返回可写全局容器的实现细节），测试间须手动清理，避免相互污染。
 */
class NonCoroutineTransactionTest extends TestCase
{
  /** @var string[] 测试产生的临时数据库文件，tearDown 统一清理 */
  private array $tmpFiles = [];

  protected function setUp(): void
  {
    // XaRecordingChannel 定义于 XaTransactionTest.php（无独立文件），显式加载
    require_once __DIR__ . '/XaTransactionTest.php';
    App::factory()->make(DbManager::class)->setDebug(false);
    Context::remove('$_db_transaction');
  }

  protected function tearDown(): void
  {
    Context::remove('$_db_transaction');
    foreach ($this->tmpFiles as $file) {
      if (is_file($file)) @unlink($file);
    }
  }

  /**
   * 场景1：非协程普通事务（跨通道闭包）提交——两通道数据均落库
   */
  public function testCommitPersistsAcrossChannels(): void
  {
    [$dbPathA, $dbPathB] = $this->makeTwoDbFiles();
    $db = $this->makeDbManager($dbPathA, $dbPathB);
    $result = $db->startTransaction(function () use ($db): string {
      $db->channel('nc_a')->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      $db->channel('nc_b')->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      self::assertSame(1, $db->transactionLevel(), '闭包内应处于首层事务');
      return 'ok';
    });
    self::assertSame('ok', $result);
    self::assertSame(0, $db->transactionLevel());
    self::assertSame(1, $this->countRows($dbPathA), '通道 A 数据应落库');
    self::assertSame(1, $this->countRows($dbPathB), '通道 B 数据应落库');
  }

  /**
   * 场景2：非协程事务回滚——闭包异常时数据零残留
   */
  public function testRollbackDiscardsAllWrites(): void
  {
    [$dbPathA, $dbPathB] = $this->makeTwoDbFiles();
    $db = $this->makeDbManager($dbPathA, $dbPathB);
    try {
      $db->startTransaction(function () use ($db): void {
        $db->channel('nc_a')->execute('INSERT INTO t (v) VALUES (?)', ['r']);
        $db->channel('nc_b')->execute('INSERT INTO t (v) VALUES (?)', ['r']);
        throw new \RuntimeException('boom');
      });
      self::fail('闭包异常应向外抛出');
    } catch (\RuntimeException) {
    }
    self::assertSame(0, $db->transactionLevel(), '回滚后层级应归零');
    self::assertSame(0, $this->countRows($dbPathA), '通道 A 写入应被回滚');
    self::assertSame(0, $this->countRows($dbPathB), '通道 B 写入应被回滚');
  }

  /**
   * 场景3：非协程 XA 事务提交——journal I/O（建表+INSERT/UPDATE/DELETE）正常执行
   */
  public function testXaTransactionCommitWritesJournalLifecycle(): void
  {
    App::factory()->get('config')->set('database.xa.journal_channel', 'nc_journal');
    App::factory()->get('config')->set('database.xa.journal_table', 'jt');
    $journalChannel = new XaRecordingChannel();
    $channelA = new XaRecordingChannel();
    $db = App::factory()->make(DbManager::class);
    $db->addChannel('nc_journal', $journalChannel);
    $db->addChannel('nc_xa_a', $channelA);
    $result = $db->startXaTransaction(function () use ($channelA): string {
      $channelA->execute('INSERT INTO t (v) VALUES (?)', ['x']);
      return 'xa-ok';
    });
    self::assertSame('xa-ok', $result);
    $journalSql = implode(';', $journalChannel->log);
    // journal 生命周期完整：建表（仅一次）→ 意图写入 → 提交标记 → 清理
    self::assertSame(1, substr_count($journalSql, 'CREATE TABLE'), '建表 DDL 应实例级缓存仅执行一次');
    self::assertStringContainsString('INSERT INTO', $journalSql);
    self::assertStringContainsString('UPDATE', $journalSql);
    self::assertStringContainsString('DELETE FROM', $journalSql);
    // 业务连接归还（非协程下 put 即 close）
    self::assertCount(1, $channelA->puts);
  }

  /**
   * 场景4：非协程恢复任务（xa:recover 命令 / workerStart 钩子的执行形态）正常收敛
   */
  public function testRecoveryRunsInNonCoroutineContext(): void
  {
    App::factory()->get('config')->set('database.xa.journal_channel', 'nc_journal');
    App::factory()->get('config')->set('database.xa.journal_table', 'jt');
    $journalChannel = new XaRecordingChannel();
    App::factory()->make(DbManager::class)->addChannel('nc_journal', $journalChannel);
    // journal 无残留行（稳态）时恢复任务仅一次探测查询，正常返回
    XaRecovery::run();
    $journalSql = implode(';', $journalChannel->log);
    self::assertStringContainsString('SELECT', $journalSql, '恢复任务应执行 journal 探测查询');
    self::assertStringNotContainsString('DELETE FROM', $journalSql, '无残留行时不应有删除操作');
  }

  /**
   * 场景5：非协程未收尾事务——脚本结束 GC 触发析构兜底全量回滚
   */
  public function testDestructRollsBackUnfinishedTransaction(): void
  {
    $dbPath = $this->makeOneDbFile();
    $channel = new PDOChannel(type: DriverType::SQLite, database: $dbPath);
    $manager = ConnectManager::factory();
    $manager->start();
    $connect = $manager->pop($channel, 'write');
    $connect->exec("INSERT INTO t (v) VALUES ('x')");
    $manager->put($channel, $connect);
    // 不收尾，unset 触发 __destruct 兜底
    unset($manager);
    self::assertSame(0, $this->countRows($dbPath), '未收尾事务应被析构全量回滚');
  }

  /**
   * 场景6：协程/非协程上下文隔离——协程内 factory 创建独立实例，
   * 非协程全局单例不被协程请求污染（workerStart 恢复后服务请求不受影响的关键）
   */
  public function testCoroutineGetsIndependentManagerInstance(): void
  {
    Context::set('$_db_transaction', 'GLOBAL_SENTINEL');
    run(function (): void {
      $manager = ConnectManager::factory();
      self::assertNotSame('GLOBAL_SENTINEL', $manager, '协程内应创建独立的协程级实例');
      self::assertInstanceOf(ConnectManager::class, $manager);
    });
    self::assertSame(
      'GLOBAL_SENTINEL',
      Context::get('$_db_transaction'),
      '协程退出后非协程全局上下文不应被污染'
    );
  }

  /* ------------------------------------------------------------------ */
  /* 辅助方法                                                            */
  /* ------------------------------------------------------------------ */

  /**
   * 创建两个临时 SQLite 库文件
   *
   * @return array{0:string,1:string} [库A路径, 库B路径]
   */
  private function makeTwoDbFiles(): array
  {
    $a = $this->makeOneDbFile();
    $b = $this->makeOneDbFile();
    return [$a, $b];
  }

  /**
   * 创建一个含 t 表的临时 SQLite 库文件
   */
  private function makeOneDbFile(): string
  {
    $path = sys_get_temp_dir() . '/viswoole_nc_tx_' . uniqid() . '.sqlite';
    (new PDO('sqlite:' . $path))->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->tmpFiles[] = $path;
    return $path;
  }

  /**
   * 注册两个临时 SQLite 通道并返回 DbManager
   *
   * @param string $dbPathA 通道 nc_a 库文件
   * @param string $dbPathB 通道 nc_b 库文件
   */
  private function makeDbManager(string $dbPathA, string $dbPathB): DbManager
  {
    $db = App::factory()->make(DbManager::class);
    $db->addChannel('nc_a', new PDOChannel(type: DriverType::SQLite, database: $dbPathA));
    $db->addChannel('nc_b', new PDOChannel(type: DriverType::SQLite, database: $dbPathB));
    return $db;
  }

  /**
   * 用独立连接统计 t 表行数
   */
  private function countRows(string $dbPath): int
  {
    return (int)(new PDO('sqlite:' . $dbPath))->query('SELECT COUNT(*) FROM t')->fetchColumn();
  }
}
