<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
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
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * ConnectManager 多事务嵌套（SAVEPOINT 方案）测试
 *
 * 与 TransactionForcePutTest 一样使用真实 SQLite 文件库 + PDOChannel，
 * 在协程内走 Db facade 全链路，验证嵌套事务的核心语义：
 * 1. 内层回滚仅丢弃该层工作，外层事务可继续（独立回滚）；
 * 2. 内层提交仅释放保存点，数据仍受最外层事务保护；
 * 3. 嵌套层中途加入事务的连接自动补齐保存点（跨通道嵌套）；
 * 4. 层级计数、连接持有周期、未收尾事务的析构全量回滚。
 */
class NestedTransactionTest extends TestCase
{
  protected function setUp(): void
  {
    App::factory()->make(DbManager::class)->setDebug(false);
  }

  /**
   * 场景1：内层回滚独立生效——只丢弃内层写入，外层数据保留且外层可继续写入
   */
  public function testInnerRollBackDiscardsOnlyInnerWork(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();                               // L1
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      Db::startTransaction();                               // L2：SAVEPOINT
      $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      Db::rollBack();                                       // 仅回滚 L2：丢弃 b
      self::assertSame(1, Db::transactionLevel(), '内层回滚后应回到第 1 层');
      // 外层事务在内层回滚后仍可继续写入
      $db->execute('INSERT INTO t (v) VALUES (?)', ['c']);
      Db::commit();                                         // L1 提交：a、c 落库
      self::assertSame(0, Db::transactionLevel());
      self::assertSame([], $this->readConnections());
    });
    self::assertSame(['a', 'c'], $this->readValues($channel), '内层 b 应回滚，外层 a/c 应落库');
  }

  /**
   * 场景2：内层提交不是真提交——仅释放保存点，外层回滚时内层已"提交"的数据一并丢弃
   */
  public function testInnerCommitIsNotRealCommit(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();                               // L1
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      Db::startTransaction();                               // L2
      $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      Db::commit();                                         // L2：RELEASE SAVEPOINT
      self::assertSame(1, Db::transactionLevel(), '内层提交后应回到第 1 层');
      Db::rollBack();                                       // L1 全量回滚（含内层数据）
    });
    self::assertSame([], $this->readValues($channel), '外层回滚应连同内层已提交的数据一并丢弃');
  }

  /**
   * 场景3：三层嵌套 + 中间层回滚——中间层与其内层的工作一并丢弃，最外层保留
   */
  public function testThreeLevelNestingWithMiddleRollBack(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();                               // L1
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      Db::startTransaction();                               // L2
      $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      Db::startTransaction();                               // L3
      $db->execute('INSERT INTO t (v) VALUES (?)', ['c']);
      self::assertSame(3, Db::transactionLevel());
      Db::rollBack();                                       // 回滚 L3：丢弃 c
      Db::rollBack();                                       // 回滚 L2：丢弃 b（含已回滚的 L3 范围）
      self::assertSame(1, Db::transactionLevel());
      Db::commit();                                         // L1：仅 a 落库
    });
    self::assertSame(['a'], $this->readValues($channel));
  }

  /**
   * 场景4：内层回滚后再次开启同层事务——保存点可重复声明，新内层数据正常保留
   */
  public function testReEnteringInnerLevelAfterRollBack(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();                               // L1
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      Db::startTransaction();                               // L2（第一次）
      $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      Db::rollBack();                                       // 丢弃 b
      Db::startTransaction();                               // L2（第二次，同名保存点被重新声明）
      $db->execute('INSERT INTO t (v) VALUES (?)', ['c']);
      Db::commit();                                         // L2 提交：c 进入外层保护范围
      Db::commit();                                         // L1 提交：a、c 落库
    });
    self::assertSame(['a', 'c'], $this->readValues($channel));
  }

  /**
   * 场景5：闭包嵌套事务——内层闭包异常仅回滚内层，外层捕获后继续提交
   */
  public function testNestedClosureTransactionRollsBackInnerOnly(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction(function () use ($db): void {
        $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
        try {
          Db::startTransaction(function () use ($db): void {
            $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
            throw new RuntimeException('inner failed');
          });
          self::fail('内层闭包异常应向外抛出');
        } catch (RuntimeException $e) {
          self::assertSame('inner failed', $e->getMessage());
        }
        self::assertSame(1, Db::transactionLevel(), '内层闭包回滚后应回到第 1 层');
        $db->execute('INSERT INTO t (v) VALUES (?)', ['c']);
      });
      self::assertSame(0, Db::transactionLevel());
    });
    self::assertSame(['a', 'c'], $this->readValues($channel));
  }

  /**
   * 场景6：嵌套层中途加入的连接补齐保存点——
   * 通道 B 在 L2 才首次写入（连接此时才 BEGIN + 补保存点），
   * 内层回滚应丢弃 B 的内层写入、保留 A 的外层写入；
   * 且两通道连接均持有到最外层收尾才归还连接池
   */
  public function testLateJoiningConnectionGetsSavepointCatchUp(): void
  {
    $channelA = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_a', $channelA);
    $channelB = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_b', $channelB);
    run(function () use ($channelA, $channelB): void {
      Db::startTransaction();                               // L1
      Db::channel('tx_a')->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      Db::startTransaction();                               // L2：A 的连接声明保存点
      // B 的连接在 L2 才加入事务：BEGIN + 补 L2 保存点
      Db::channel('tx_b')->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      Db::rollBack();                                       // L2：A/B 均回滚到各自 sp2
      self::assertSame(1, Db::transactionLevel());
      // 内层收尾后连接仍被外层事务持有（未归还连接池）
      self::assertCount(2, $this->readConnections(), '两通道连接均应仍在事务池中');
      self::assertSame(0, $this->readPoolLength($channelA));
      self::assertSame(0, $this->readPoolLength($channelB));
      Db::commit();                                         // L1：释放并归还全部连接
      self::assertSame([], $this->readConnections());
      self::assertSame(1, $this->readPoolLength($channelA));
      self::assertSame(1, $this->readPoolLength($channelB));
    });
    // A 的外层写入保留（保存点声明于其写入之后），B 的内层写入丢弃
    self::assertSame(['a'], $this->readValues($channelA));
    self::assertSame([], $this->readValues($channelB));
  }

  /**
   * 场景7：层级计数——start/commit/rollBack 对 transactionLevel 的增减语义
   */
  public function testTransactionLevelTracking(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function (): void {
      self::assertSame(0, Db::transactionLevel());
      Db::startTransaction();
      self::assertSame(1, Db::transactionLevel());
      Db::startTransaction();
      self::assertSame(2, Db::transactionLevel());
      Db::commit();
      self::assertSame(1, Db::transactionLevel());
      Db::startTransaction();
      self::assertSame(2, Db::transactionLevel());
      Db::rollBack();
      self::assertSame(1, Db::transactionLevel());
      Db::rollBack();
      self::assertSame(0, Db::transactionLevel());
    });
  }

  /**
   * 场景8：嵌套事务未收尾——协程结束时析构应全量回滚最外层事务并归还连接
   */
  public function testUncommittedNestedTransactionFullyRolledBackOnDestruct(): void
  {
    $tmpDb = $this->makeSqliteFile('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $channel = new NestedRecordingChannel($tmpDb);
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();                                    // L1
      $conn = $manager->pop($channel, 'write');
      $conn->exec("INSERT INTO t (v) VALUES ('a')");
      $manager->put($channel, $conn);                       // 模拟 execute 完毕归还（事务内仅标记空闲）
      $manager->start();                                    // L2：复用连接并声明保存点
      $conn2 = $manager->pop($channel, 'write');
      self::assertSame($conn, $conn2, '嵌套层应复用同一空闲连接');
      $conn2->exec("INSERT INTO t (v) VALUES ('b')");
      $manager->put($channel, $conn2);
      // 两层事务均不收尾，交给协程结束时 ConnectManager 析构兜底
    });
    self::assertCount(1, $channel->puts, '析构应全量回滚并归还事务连接');
    self::assertFalse($channel->connection->inTransaction(), '析构后连接不应残留事务状态');
    self::assertSame(0, $this->countRows($tmpDb, 't'), '未收尾的嵌套事务数据应被全量回滚');
  }

  /**
   * 注册 SQLite 通道到 DbManager
   */
  private function registerChannel(string $name, PDOChannel $channel): void
  {
    App::factory()->make(DbManager::class)->addChannel($name, $channel);
  }

  /**
   * 创建临时 SQLite 数据库文件并建表
   *
   * @param string $ddl 建表语句
   * @return string 数据库文件路径
   */
  private function makeSqliteFile(string $ddl): string
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_nested_tx_' . uniqid() . '.sqlite';
    (new PDO('sqlite:' . $tmpDb))->exec($ddl);
    return $tmpDb;
  }

  /**
   * 创建基于临时 SQLite 文件的通道并初始化数据
   *
   * @param string $ddl 建表语句
   * @return PDOChannel 已就绪的通道
   */
  private function makeSqliteChannel(string $ddl): PDOChannel
  {
    return new PDOChannel(type: DriverType::SQLite, database: $this->makeSqliteFile($ddl));
  }

  /**
   * 读取 t 表全部 v 字段值（协程外用新连接验证落库结果）
   *
   * @return string[] 按 id 升序的 v 值列表
   */
  private function readValues(PDOChannel $channel): array
  {
    return array_column($channel->table('t')->get()->toArray(), 'v');
  }

  /**
   * 用独立连接统计指定表的行数（协程外验证落库结果）
   *
   * @param string $tmpDb 数据库文件路径
   * @param string $table 表名
   * @return int 行数
   */
  private function countRows(string $tmpDb, string $table): int
  {
    return (int)(new PDO('sqlite:' . $tmpDb))->query("SELECT COUNT(*) FROM $table")->fetchColumn();
  }

  /**
   * 读取当前协程 ConnectManager 的事务连接列表（反射）
   */
  private function readConnections(): array
  {
    $manager = ConnectManager::factory();
    $prop = new ReflectionProperty(ConnectManager::class, 'connections');
    return $prop->getValue($manager);
  }

  /**
   * 读取单库通道的连接池长度（反射 pool 属性）
   */
  private function readPoolLength(PDOChannel $channel): int
  {
    $prop = new ReflectionProperty(PDOChannel::class, 'pool');
    $pool = $prop->getValue($channel);
    self::assertInstanceOf(\Viswoole\Database\Channel\PDO\PDOPool::class, $pool, '测试通道应为单库结构');
    return $pool->length();
  }
}

/**
 * 归还记录通道
 *
 * pop 返回真实 SQLite 文件库连接（beginTransaction/exec/rollBack/inTransaction
 * 全部作用于磁盘库），put 仅记录调用用于断言析构归还行为。
 */
class NestedRecordingChannel extends Channel
{
  /** @var PDO 真实 SQLite 连接 */
  public readonly PDO $connection;
  /** @var PDO[] 记录每次归还的连接 */
  public array $puts = [];

  public function __construct(string $databasePath)
  {
    $this->connection = new PDO('sqlite:' . $databasePath);
  }

  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): \Swoole\Database\PDOStatementProxy|\PDOStatement|int|string {
    return 0;
  }

  public function pop(string $type): mixed
  {
    return $this->connection;
  }

  public function put(mixed $connect): void
  {
    $this->puts[] = $connect;
  }

  public function build(Options $options): Raw
  {
    return new Raw('');
  }
}
