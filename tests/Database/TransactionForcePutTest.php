<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use function Swoole\Coroutine\run;
use Throwable;
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
 * ConnectManager::forcePut 事务强制归还逻辑的真实场景测试
 *
 * 与桩测试不同，本套件使用真实 SQLite 文件库 + PDOChannel + 连接池，
 * 在协程内走 Db facade 全链路（start/execute/commit/rollBack），
 * 验证事务提交、回滚、提交失败兜底、连接复用与归还的真实行为。
 */
class TransactionForcePutTest extends TestCase
{
  protected function setUp(): void
  {
    App::factory()->make(DbManager::class)->setDebug(false);
  }

  /**
   * 场景1：真实事务提交——数据落库、连接归还连接池、事务状态清空
   */
  public function testCommitPersistsDataAndReleasesConnection(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      Db::commit();
      // 事务状态应完全清空
      self::assertSame([], $this->readConnections());
      // 连接应归还连接池（单库池 length 恢复为 1）
      self::assertSame(1, $this->readPoolLength($channel), 'commit 后连接应归还连接池');
    });
    // 协程外验证：数据真实落库（新连接查询）
    self::assertSame(2, $channel->table('t')->count());
  }

  /**
   * 场景2：真实事务回滚——数据丢弃、连接归还
   */
  public function testRollBackDiscardsDataAndReleasesConnection(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      Db::rollBack();
      self::assertSame([], $this->readConnections());
      self::assertSame(1, $this->readPoolLength($channel), 'rollBack 后连接应归还连接池');
    });
    self::assertSame(0, $channel->table('t')->count(), '回滚后数据不应落库');
  }

  /**
   * 场景3：commit 真实失败（故障注入：真实 SQLite 连接仅拦截 commit）
   *
   * 验证 forcePut 兜底回滚：事务状态清理、数据不落库、连接归还。
   */
  public function testCommitFailureRollsBackAndKeepsConnectionReusable(): void
  {
    $tmpDb = $this->makeSqliteFile('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $channel = new CommitInterceptChannel($tmpDb);
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $conn = $manager->pop($channel, 'write');
      $conn->exec("INSERT INTO t (v) VALUES ('a')");
      try {
        $manager->commit();
        self::fail('commit 应被故障注入拦截抛出异常');
      } catch (PDOException) {
        // 预期：commit 阶段服务器故障
      }
      self::assertSame([], $this->readConnections(), 'commit 失败后事务状态应清空');
      self::assertFalse($conn->inTransaction(), '兜底回滚应清理连接的事务状态');
      self::assertSame($conn, $channel->bag['put'], 'commit 失败后连接应强制归还');
    });
    self::assertSame(0, $this->countRows($tmpDb, 't'), 'commit 失败的数据应被兜底回滚');
  }

  /**
   * 场景4：commit 失败后的连接立即开启第二个事务应正常工作
   *
   * 若 forcePut 未清理事务状态，beginTransaction 会抛
   * "There is already an active transaction"。
   */
  public function testConnectionReusableImmediatelyAfterFailedCommit(): void
  {
    $tmpDb = $this->makeSqliteFile('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $channel = new CommitInterceptChannel($tmpDb);
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      // 第一次事务：commit 故障失败
      $manager->start();
      $conn = $manager->pop($channel, 'write');
      $conn->exec("INSERT INTO t (v) VALUES ('a')");
      try {
        $manager->commit();
      } catch (PDOException) {
        // 预期失败
      }
      self::assertFalse($conn->inTransaction(), '失败事务的连接应已回滚清理');
      // 第二次事务：同一连接复用，关闭故障注入后必须正常开启并提交
      $conn->failCommit = false;
      $manager->start();
      $conn2 = $manager->pop($channel, 'write');
      self::assertSame($conn, $conn2, '应复用同一连接');
      $conn2->exec("INSERT INTO t (v) VALUES ('b')");
      $manager->commit();
    });
    self::assertSame(1, $this->countRows($tmpDb, 't'), '只有第二个事务的数据应落库');
  }

  /**
   * 场景5：事务内多次 execute 只占用一个连接（空闲标记→复用）
   */
  public function testTransactionReusesSingleConnectionAcrossExecutes(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      $db = Db::channel('tx_main');
      Db::startTransaction();
      $db->execute('INSERT INTO t (v) VALUES (?)', ['a']);
      $db->execute('INSERT INTO t (v) VALUES (?)', ['b']);
      $db->execute('SELECT * FROM t');
      // 多次 execute 后事务连接数仍应为 1（put 标记空闲 → pop 复用）
      self::assertCount(1, $this->readConnections(), '事务内多次执行应复用同一连接');
      Db::commit();
      self::assertSame(1, $this->readPoolLength($channel), '整个事务只应消耗一个池连接');
    });
  }

  /**
   * 场景6：rollBack 双重失败（连接已失效）——forcePut 吞掉二次回滚异常，
   * 连接仍强制归还，进程不出现致命错误
   */
  public function testRollBackFailureStillForcesPutWithoutFatal(): void
  {
    $bag = new \ArrayObject(['rollBack' => 0, 'put' => null]);
    $channel = new class($bag) extends Channel {
      public function __construct(private readonly \ArrayObject $bag) {}

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
        // rollBack 必然失败的失效连接（beginTransaction 正常）
        return new class($this->bag) extends PDO {
          public function __construct(private readonly \ArrayObject $bag)
          {
            parent::__construct('sqlite::memory:');
          }

          public function rollBack(): bool
          {
            $this->bag['rollBack']++;
            throw new PDOException('server has gone away');
          }
        };
      }

      public function put(mixed $connect): void
      {
        $this->bag['put'] = $connect;
      }

      public function build(Options $options): Raw
      {
        return new Raw('');
      }
    };
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->pop($channel, 'write');
      try {
        $manager->rollBack();
        self::fail('rollBack 应向外抛出失效连接的异常');
      } catch (PDOException) {
        // 外层 rollBack 异常应正常冒泡给调用方处理
      }
    });
    self::assertSame(2, $bag['rollBack'], 'rollBack 失败后 forcePut 应再尝试一次兜底回滚');
    self::assertNotNull($bag['put'], '兜底回滚失败时连接仍应强制归还');
  }

  /**
   * 场景7：多通道事务中途 commit 失败——已提交通道数据落库，
   * 失败通道被兜底回滚（当前无 2PC 的已知部分提交语义，行为锁定）
   */
  public function testMultiChannelTransactionPartialCommitSemantics(): void
  {
    $channelA = $this->makeSqliteChannel('CREATE TABLE a (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_a', $channelA);
    $tmpB = $this->makeSqliteFile('CREATE TABLE b (id INTEGER PRIMARY KEY, v TEXT)');
    $channelB = new CommitInterceptChannel($tmpB);
    run(function () use ($channelA, $channelB): void {
      $manager = ConnectManager::factory();
      $manager->start();
      // 通道 A：真实链路写入（先加入事务，commit 顺序在前）
      Db::channel('tx_a')->execute('INSERT INTO a (v) VALUES (?)', ['ok']);
      // 通道 B：真实 SQLite 连接，commit 将被故障注入拦截
      $connB = $manager->pop($channelB, 'write');
      $connB->exec("INSERT INTO b (v) VALUES ('bad')");
      try {
        $manager->commit();
        self::fail('tx_b 的 commit 故障应导致整体 commit 抛出异常');
      } catch (PDOException) {
        // 预期：tx_b commit 失败
      }
      self::assertSame([], $this->readConnections());
      self::assertSame(1, $this->readPoolLength($channelA), 'tx_a 连接应归还');
      self::assertSame($connB, $channelB->bag['put'], 'tx_b 连接应强制归还');
    });
    // 已知语义：先提交的通道数据保留（无跨连接 2PC）
    self::assertSame(1, $channelA->table('a')->count(), '已成功提交的 tx_a 数据应保留');
    self::assertSame(0, $this->countRows($tmpB, 'b'), 'commit 失败的 tx_b 数据应回滚');
  }

  /**
   * 场景9：协程并发事务隔离——两个并发子协程各自开启事务（一个提交一个回滚），
   * 基于 Swoole 协程上下文天然隔离，ConnectManager 互不共享，
   * "同一进程中不允许开启多个事务"仅约束单协程内嵌套开启
   */
  public function testConcurrentCoroutinesHaveIsolatedTransactions(): void
  {
    $channelA = $this->makeSqliteChannel('CREATE TABLE t (v TEXT)');
    $this->registerChannel('tx_a', $channelA);
    $channelB = $this->makeSqliteChannel('CREATE TABLE t (v TEXT)');
    $this->registerChannel('tx_b', $channelB);
    run(function () use ($channelA, $channelB): void {
      $results = new \ArrayObject(['commit' => null, 'rollback' => null]);
      $wg = new \Swoole\Coroutine\WaitGroup();
      $wg->add();
      \Swoole\Coroutine::create(function () use ($wg, $results): void {
        try {
          Db::startTransaction();
          Db::channel('tx_a')->execute('INSERT INTO t (v) VALUES (?)', ['c1']);
          Db::commit();
          $results['commit'] = 'ok';
        } catch (\Throwable $e) {
          $results['commit'] = $e->getMessage();
        } finally {
          $wg->done();
        }
      });
      $wg->add();
      \Swoole\Coroutine::create(function () use ($wg, $results): void {
        try {
          Db::startTransaction();
          Db::channel('tx_b')->execute('INSERT INTO t (v) VALUES (?)', ['c2']);
          Db::rollBack();
          $results['rollback'] = 'ok';
        } catch (\Throwable $e) {
          $results['rollback'] = $e->getMessage();
        } finally {
          $wg->done();
        }
      });
      $wg->wait();
      // 两个协程的事务均应正常完成，互不触发"不允许开启多个事务"
      self::assertSame('ok', $results['commit'], '协程1 事务应正常提交：' . $results['commit']);
      self::assertSame('ok', $results['rollback'], '协程2 事务应正常回滚：' . $results['rollback']);
      // 各自通道的连接均应归还连接池
      self::assertSame(1, $this->readPoolLength($channelA), '协程1 事务连接应归还');
      self::assertSame(1, $this->readPoolLength($channelB), '协程2 事务连接应归还');
    });
    self::assertSame(1, $channelA->table('t')->count(), '协程1 提交的数据应落库');
    self::assertSame(0, $channelB->table('t')->count(), '协程2 回滚的数据不应落库');
  }

  /**
   * 场景10：父协程事务进行中，子协程并发非事务写入——
   * 子协程持有独立 ConnectManager（Swoole 上下文不自动继承），
   * 其写入走独立连接自动提交，不受父事务影响，也不被父事务回滚波及
   *
   * 注：子协程写入另一通道（独立 SQLite 文件），因 SQLite 文件库为库级写锁，
   * 父事务持锁期间无法并发写同一文件（MySQL 行级锁无此限制），
   * 使用独立文件不影响对协程上下文隔离语义的验证
   */
  public function testChildCoroutineWriteIsIndependentOfParentTransaction(): void
  {
    $mainChannel = $this->makeSqliteChannel('CREATE TABLE t (v TEXT)');
    $this->registerChannel('tx_main', $mainChannel);
    $otherChannel = $this->makeSqliteChannel('CREATE TABLE t (v TEXT)');
    $this->registerChannel('tx_other', $otherChannel);
    run(function () use ($mainChannel, $otherChannel): void {
      Db::startTransaction();
      Db::channel('tx_main')->execute('INSERT INTO t (v) VALUES (?)', ['parent']);
      $childError = new \ArrayObject(['message' => null]);
      $wg = new \Swoole\Coroutine\WaitGroup();
      $wg->add();
      \Swoole\Coroutine::create(function () use ($wg, $childError): void {
        try {
          // 子协程独立上下文：无需 startTransaction 即可写入，立即自动提交
          Db::channel('tx_other')->execute('INSERT INTO t (v) VALUES (?)', ['child']);
        } catch (\Throwable $e) {
          $childError['message'] = $e->getMessage();
        } finally {
          $wg->done();
        }
      });
      $wg->wait();
      self::assertNull($childError['message'], '子协程写入不应受父事务影响：' . $childError['message']);
      // 父事务回滚：子协程已自动提交的数据保留，父事务数据丢弃
      Db::rollBack();
    });
    $mainRows = array_column($mainChannel->table('t')->get()->toArray(), 'v');
    $otherRows = array_column($otherChannel->table('t')->get()->toArray(), 'v');
    self::assertContains('child', $otherRows, '子协程自动提交的数据应保留');
    self::assertNotContains('parent', $mainRows, '父事务回滚的数据应丢弃');
  }

  /**
   * 场景8：空事务——start 后未执行任何语句直接 commit/rollBack 应静默通过
   */
  public function testEmptyTransactionCommitAndRollBackAreNoOps(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
    $this->registerChannel('tx_main', $channel);
    run(function () use ($channel): void {
      Db::startTransaction();
      Db::commit();
      self::assertSame([], $this->readConnections());
      Db::startTransaction();
      Db::rollBack();
      self::assertSame([], $this->readConnections());
      self::assertSame(0, $this->readPoolLength($channel), '空事务不应占用池连接');
    });
    self::assertSame(0, $channel->table('t')->count());
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
    $tmpDb = sys_get_temp_dir() . '/viswoole_tx_' . uniqid() . '.sqlite';
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
 * commit 故障注入通道
 *
 * pop 返回真实 SQLite 文件库连接（beginTransaction/exec/rollBack/inTransaction
 * 全部作用于磁盘库），仅 commit() 可被开关拦截为抛异常，
 * 用于确定性复现"commit 阶段服务器故障"的真实事务场景。
 */
class CommitInterceptChannel extends Channel
{
  /** @var PDO&object{failCommit:bool} 真实 SQLite 连接（commit 可拦截） */
  public readonly PDO $connection;
  /** @var \ArrayObject 记录强制归还的连接 */
  public readonly \ArrayObject $bag;

  public function __construct(string $databasePath)
  {
    $this->bag = new \ArrayObject(['put' => null]);
    $this->connection = new class($databasePath) extends PDO {
      /** @var bool 是否拦截下一次 commit 为故障 */
      public bool $failCommit = true;

      public function __construct(string $databasePath)
      {
        parent::__construct('sqlite:' . $databasePath);
      }

      public function commit(): bool
      {
        if ($this->failCommit) {
          throw new PDOException('server has gone away during commit');
        }
        return parent::commit();
      }
    };
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
    $this->bag['put'] = $connect;
  }

  public function build(Options $options): Raw
  {
    return new Raw('');
  }
}
