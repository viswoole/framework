<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use function Swoole\Coroutine\run;
use Throwable;
use Viswoole\Core\App;
use Viswoole\Database\Channel;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Channel\PDO\SqlBuilder;
use Viswoole\Database\Collection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;
use Viswoole\Database\Model\RelationQuery;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Query\WhereGroup;
use Viswoole\Database\Raw;

/**
 * Database 模块第二轮代码审查问题复现测试
 *
 * 每个用例对应第二轮审查报告中的 P0/P1 缺陷，断言"修复后应具备的正确行为"。
 * 修复前运行应失败（红），修复后全部通过（绿）。
 */
class Review2RegressTest extends TestCase
{
  /**
   * 统一关闭数据库调试输出
   *
   * Output::echo 会关闭输出缓冲导致 PHPUnit 报 risky，
   * 真实 SQLite 通道执行的用例均需静默。
   */
  protected function setUp(): void
  {
    App::factory()->make(DbManager::class)->setDebug(false);
  }
  /**
   * P0-1：having() 的 operator 由调用方任意提供且被 SqlBuilder 原样内插，
   * 恶意运算符可注入 HAVING 子句（与 where 同类注入面）。
   * 期望：非法运算符在构建阶段即抛 InvalidArgumentException。
   */
  public function testHavingRejectsOperatorOutsideWhitelist(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('运算符');
    $query->having('score', '> 1 OR 1=1 --', 1);
  }

  /**
   * P0-1（续）：having() 的 connector 同样未校验，需一并拦截。
   */
  public function testHavingRejectsInvalidConnector(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('连接符');
    $query->having('score', '>', 1, 'XOR evil');
  }

  /**
   * P0-2：whereBetween 的数组值被 IN 分支格式化为 BETWEEN (?, ?)，
   * 属非法 SQL 语法，BETWEEN 从未正确工作过。
   * 期望：生成 BETWEEN ? AND ?，查询正常返回。
   */
  public function testWhereBetweenBuildsBetweenAndPlaceholders(): void
  {
    $channel = $this->makeSqliteChannel(
      'CREATE TABLE t (id INTEGER, age INTEGER)',
      ['INSERT INTO t VALUES (1, 18), (2, 20), (3, 25)']
    );
    try {
      $rows = $channel->table('t')->whereBetween('age', [19, 24])->getArray();
      self::assertCount(1, $rows, 'BETWEEN 条件应命中 age=20 的一行');
      self::assertSame(2, $rows[0]['id']);
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P0-2（续）：BETWEEN 边界值必须且只能为两个，其余在构建期拦截。
   */
  public function testWhereBetweenRequiresTwoBounds(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (id INTEGER, age INTEGER)', []);
    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('两个边界');
      $channel->table('t')->whereBetween('age', [1, 2, 3])->getArray();
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P0-3：OPERATORS 白名单放行 EXISTS/NOT EXISTS，但 SqlBuilder
   * 无对应生成分支，手写 where('col','EXISTS',...) 会生成非法 SQL。
   * 期望：从白名单移除后构建期即拒绝（合法入口是 whereExists()）。
   */
  public function testWhereRejectsExistsOperator(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('运算符');
    $query->where('id', 'EXISTS', 1);
  }

  /**
   * P0-4：getType() 仅识别 SELECT 开头，SHOW/WITH 等读语句被路由到写库，
   * 且写库操作触发 sticky 将后续读全部粘到写库。
   * 期望：复用 isQueryStatement 判定，SHOW/WITH 走读库。
   */
  public function testGetTypeRoutesNonSelectReadStatementsToRead(): void
  {
    $channel = new PDOChannel(type: DriverType::MYSQL, host: '127.0.0.1');
    $method = new \ReflectionMethod(PDOChannel::class, 'getType');
    self::assertSame('read', $method->invoke($channel, 'SELECT * FROM t'));
    self::assertSame('read', $method->invoke($channel, 'SHOW TABLES'), 'SHOW 应路由到读库');
    self::assertSame('read', $method->invoke($channel, 'WITH a AS (SELECT 1) SELECT * FROM a'));
    self::assertSame('read', $method->invoke($channel, 'DESCRIBE t'));
    self::assertSame('write', $method->invoke($channel, 'UPDATE t SET a = 1'));
  }

  /**
   * P0-5：execute() 用 str_starts_with($sql, 'INSERT') 大小写敏感判断，
   * 小写 SQL 时 insertGetId 静默丢失自增 ID。
   * 期望：小写 insert 同样返回 lastInsertId。
   */
  public function testExecuteLowercaseInsertReturnsLastInsertId(): void
  {
    $channel = $this->makeSqliteChannel(
      'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
      []
    );
    try {
      $id = $channel->execute('insert into t (name) values (?)', ['a'], 'id');
      self::assertEquals(1, $id, '小写 insert 也应返回自增 ID');
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P0-6：表结构静态缓存仅按表名索引，不同通道（库）存在同名表时
   * 结构相互污染，strict(false) 场景下静默丢弃另一库的有效字段。
   * 期望：缓存按通道实例隔离，两个通道各自取到正确表结构。
   */
  public function testTableColumnsCacheIsChannelScoped(): void
  {
    $channelA = $this->makeSqliteChannel('CREATE TABLE cache_t (a TEXT, b TEXT)', []);
    $channelB = $this->makeSqliteChannel('CREATE TABLE cache_t (a TEXT, c TEXT)', []);
    try {
      // 先查 A 触发缓存写入（修复前键为裸表名，会污染 B）
      $channelA->table('cache_t')->strict(false)->insert(['a' => 'x', 'b' => 'y']);
      // B 库的 cache_t 无 b 有 c：修复前 B 复用 A 的 [a,b] 缓存导致 c 被静默丢弃
      $channelB->table('cache_t')->strict(false)->insert(['a' => 'x', 'c' => 'z']);
      $rows = $channelB->table('cache_t')->getArray();
      self::assertCount(1, $rows);
      self::assertSame('z', $rows[0]['c'], 'B 通道的 c 字段不应被 A 通道的缓存结构丢弃');
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P0-7：RelationQuery 收集外键时未过滤空值/去重，
   * 主数据缺少外键列时 whereIn 因空数组误报异常。
   * 期望：无有效外键时跳过查询，关联字段填充为空数据集。
   */
  public function testRelationQueryToleratesMissingLocalKey(): void
  {
    $rows = null;
    $error = null;
    run(function () use (&$rows, &$error): void {
      try {
        $db = App::factory()->make(DbManager::class);
        $db->setDebug(false);
        // 主数据行没有 id 列（localKey 缺失）
        $db->addChannel('review_main', new SleepChannel($this->makeNamedStatement([['name' => 'a'], ['name' => 'b']]), 0.0));
        $db->addChannel('review_rel_a', new SleepChannel($this->makeRowsStatement([['id' => 10, 'user_id' => 1]]), 0.0));
        $rows = (new ReviewMainModel())->query->with(['profile'])->getArray();
      } catch (Throwable $e) {
        $error = $e;
      }
    });
    self::assertNull($error, '缺少外键列时不应抛出异常：' . $error);
    self::assertCount(2, $rows, '主数据应全部返回');
    self::assertInstanceOf(DataSet::class, $rows[0]['profile']);
    self::assertCount(0, $rows[0]['profile'], '未命中外键的关联应为空数据集');
  }

  /**
   * P0-8：Collection::update() 成功后 merge() 会把同步字段记入变更，
   * 后续 save() 把刚批量更新的字段再写一遍（双重更新）。
   * 期望：同步后清空变更追踪，save() 不再触发 SQL。
   */
  public function testCollectionUpdateDoesNotDirtyRows(): void
  {
    $channel = new FakeChannel(1);
    $query = $channel->table('users');
    $collection = new Collection($query, [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
    $result = $collection->update(['name' => 'x']);
    self::assertSame(1, $result);
    /** @var DataSet $row */
    $row = $collection[0];
    // 修复前：save() 会再执行一次 UPDATE（calls 计数为 2）
    self::assertFalse($row->save(), '同步后的行不应再有变更');
    self::assertCount(1, $channel->calls, '同步数据后 save() 不应再次触发 UPDATE');
  }

  /**
   * P0-9a：withoutColumns 排除全部字段后生成 "SELECT  FROM t" 非法 SQL。
   * 期望：构建期抛 InvalidArgumentException 而非语法错误。
   */
  public function testWithoutColumnsExcludingAllThrows(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (a TEXT, b TEXT)', []);
    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('全部字段');
      $channel->table('t')->withoutColumns('a', 'b')->getArray();
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P0-9b：strict(false) 过滤后无有效字段时生成 "INSERT INTO t () VALUES ()"。
   * 期望：构建期抛 InvalidArgumentException 而非语法错误。
   */
  public function testInsertFilteredToEmptyThrows(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (a TEXT)', []);
    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('无有效字段');
      $channel->table('t')->strict(false)->insert(['b' => 1]);
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P0-9c：批量插入以首行字段为准，后续行多余字段被静默丢弃。
   * 期望：行间字段不一致时构建期抛 InvalidArgumentException。
   */
  public function testBatchInsertRejectsInconsistentRows(): void
  {
    $channel = $this->makeSqliteChannel('CREATE TABLE t (a TEXT, b TEXT)', []);
    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('不一致');
      $channel->table('t')->insert([['a' => 1], ['a' => 2, 'b' => 3]]);
    } finally {
      $this->flushTableColumnsCache();
    }
  }

  /**
   * P1-10：非协程环境（CLI/单元测试）下 Coroutine::create + WaitGroup 崩溃，
   * with() 只能在协程内使用。
   * 期望：非协程环境自动降级为串行查询。
   */
  public function testWithWorksOutsideCoroutine(): void
  {
    $db = App::factory()->make(DbManager::class);
    $db->setDebug(false);
    $db->addChannel('review_main', new SleepChannel($this->makeRowsStatement([['id' => 1, 'user_id' => 0]]), 0.0));
    $db->addChannel('review_rel_a', new SleepChannel($this->makeRowsStatement([['id' => 10, 'user_id' => 1]]), 0.0));
    // 修复前：非协程下 Coroutine::create 直接报错
    $rows = (new ReviewMainModel())->query->with(['profile'])->getArray();
    self::assertCount(1, $rows);
    self::assertSame(10, $rows[0]['profile']['id'], '非协程环境关联数据应正常填充');
  }

  /**
   * P1-11：commit/close 释放连接时事务标志尚未重置，连接归还依赖
   * "先 unset 再 put 恰好遍历不到" 的脆弱顺序。重构为强制归还后
   * 验证：提交后连接真正归还到通道连接池。
   */
  public function testCommitReleasesConnectionToPool(): void
  {
    $channel = new RecordingPutChannel();
    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->pop($channel, 'write');
      $manager->commit();
    });
    self::assertCount(1, $channel->putCalls, '提交后连接应真正归还到通道');
  }

  /**
   * P1-12：commit 失败的连接可能仍处于活跃事务，直接回池会被下一个
   * 协程复用造成隐式事务污染。
   * 期望：强制归还前对未结束事务兜底回滚。
   */
  public function testBrokenConnectionRolledBackBeforePut(): void
  {
    $broken = new class('sqlite::memory:') extends PDO {
      /** @var string[] 方法调用记录 */
      public array $log = [];

      public function commit(): bool
      {
        throw new PDOException('server has gone away');
      }

      public function rollBack(): bool
      {
        $this->log[] = 'rollback';
        return true;
      }
    };
    $channel = new class($broken) extends Channel {
      public function __construct(private readonly PDO $pdo)
      {
      }

      public function execute(
        string|Raw   $sql,
        array        $bindings = [],
        false|string $getId = false,
        bool         $master = false
      ): \Swoole\Database\PDOStatementProxy|\PDOStatement|int|string
      {
        return 0;
      }

      public function pop(string $type): mixed
      {
        return $this->pdo;
      }

      public function put(mixed $connect): void
      {
      }

      public function build(Options $options): Raw
      {
        return new Raw('');
      }
    };
    run(function () use ($channel, $broken): void {
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->pop($channel, 'write');
      try {
        $manager->commit();
      } catch (PDOException) {
        // commit 失败路径正是要验证的场景
      }
    });
    self::assertContains('rollback', $broken->log, '归还前应对未完成事务兜底回滚');
  }

  /**
   * P1-13：max()/min() 中 NULL 列值按 0 参与比较，全 NULL 列返回 0 而非 null。
   * 期望：NULL 值跳过（与 SQL 聚合语义一致）。
   */
  public function testMaxMinIgnoreNullColumnValues(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $collection = new Collection($query, [['v' => null], ['v' => 5]]);
    self::assertSame(5, $collection->max('v'));
    self::assertSame(5, $collection->min('v'), 'NULL 不应按 0 参与最小值比较');
    $allNull = new Collection($query, [['v' => null], ['v' => null]]);
    self::assertNull($allNull->max('v'), '全 NULL 列应返回 null 而非 0');
  }

  /**
   * P1-14：WhereGroup 构造器对非法连接符静默纠正为 OR，
   * 与 parsing() 的抛异常行为不一致。
   * 期望：非法连接符直接抛 InvalidArgumentException。
   */
  public function testWhereGroupRejectsInvalidConnector(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('连接符');
    new WhereGroup([['id', '=', 1]], 'XOR');
  }

  /**
   * P1-16：字段改回原值（A→B→A）后仍留在变更列表，save() 产生无意义 UPDATE。
   * 期望：改回原值视为无变更。
   */
  public function testDataSetRestoredValueNotDirty(): void
  {
    $channel = new FakeChannel(0);
    $query = $channel->table('users');
    $row = new DataSet($query, ['id' => 1, 'v' => 'a']);
    $row['v'] = 'b';
    $row['v'] = 'a';
    self::assertFalse($row->save(), '改回原值应视为无变更');
    self::assertCount(0, $channel->calls, '无变更时不应执行任何 SQL');
  }

  /**
   * P1-17：chunk() 生成器中途 break 后 reset() 永不执行，
   * LIMIT/OFFSET 残留污染该查询实例的后续使用。
   * 期望：生成器销毁时通过 finally 保证重置。
   */
  public function testChunkResetsQueryAfterEarlyBreak(): void
  {
    $rows = [];
    for ($i = 1; $i <= 10; $i++) $rows[] = ['id' => $i, 'user_id' => $i];
    $channel = new FakeChannel($this->makeRowsStatement($rows));
    $query = $channel->table('users');
    $generator = $query->chunk(3);
    // 只消费第一批后中断（FakeChannel 忽略 LIMIT，首批返回全部 10 行）
    foreach ($generator as $batch) {
      self::assertCount(10, $batch);
      break;
    }
    // 销毁生成器触发 finally 重置
    unset($generator);
    gc_collect_cycles();
    $options = $this->readOptions($query);
    self::assertNull($options->limit, '中途 break 后 LIMIT 应被重置');
    self::assertNull($options->offset, '中途 break 后 OFFSET 应被重置');
  }

  /**
   * 创建基于临时 SQLite 文件的通道并初始化数据
   *
   * @param string $ddl 建表语句
   * @param string[] $inserts 初始化数据语句
   * @return PDOChannel 已就绪的通道
   */
  private function makeSqliteChannel(string $ddl, array $inserts): PDOChannel
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_review2_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec($ddl);
    foreach ($inserts as $insert) $pdo->exec($insert);
    return new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
  }

  /**
   * 清理表结构静态缓存，避免跨用例污染
   * （修复前 flush API 尚不存在，做存在性守卫保持红绿兼容）
   */
  private function flushTableColumnsCache(): void
  {
    if (method_exists(SqlBuilder::class, 'flushTableColumnsCache')) {
      SqlBuilder::flushTableColumnsCache();
    }
  }

  /**
   * 构建返回指定行集、不含 id 列的 SELECT 语句（P0-7 用）
   *
   * @param array<int,array<string,mixed>> $rows 行数据
   */
  private function makeNamedStatement(array $rows): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE t (name TEXT)');
    $stmt = $pdo->prepare('INSERT INTO t (name) VALUES (?)');
    foreach ($rows as $row) $stmt->execute([$row['name']]);
    return $pdo->query('SELECT name FROM t');
  }

  /**
   * 复用第一轮测试的行集构建器（含 id/user_id 两列）
   *
   * @param array<int,array<string,mixed>> $rows 行数据
   */
  private function makeRowsStatement(array $rows): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE t (id INTEGER, user_id INTEGER)');
    $stmt = $pdo->prepare('INSERT INTO t (id, user_id) VALUES (?, ?)');
    foreach ($rows as $row) $stmt->execute([$row['id'], $row['user_id'] ?? null]);
    return $pdo->query('SELECT id, user_id FROM t ORDER BY id');
  }

  /**
   * 反射读取查询实例的 Options（P1-17 断言重置状态用）
   */
  private function readOptions(\Viswoole\Database\BaseQuery $query): Options
  {
    $prop = new ReflectionProperty(\Viswoole\Database\BaseQuery::class, 'options');
    return $prop->getValue($query);
  }
}

/**
 * 记录 put 调用的通道替身（P1-11 用）
 */
class RecordingPutChannel extends Channel
{
  /** @var array<int,mixed> 归还的连接列表 */
  public array $putCalls = [];

  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): \Swoole\Database\PDOStatementProxy|\PDOStatement|int|string
  {
    return 0;
  }

  public function pop(string $type): mixed
  {
    return new PDO('sqlite::memory:');
  }

  public function put(mixed $connect): void
  {
    $this->putCalls[] = $connect;
  }

  public function build(Options $options): Raw
  {
    return new Raw('');
  }
}
