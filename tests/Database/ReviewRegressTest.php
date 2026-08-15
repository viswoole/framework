<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Channel\PDO\PDOProxy;
use Viswoole\Database\Channel;
use Viswoole\Database\Collection;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model;
use Viswoole\Database\Model\RelationQuery;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * Database 模块代码审查问题复现测试
 *
 * 每个用例对应审查报告中的一个缺陷，断言"修复后应具备的正确行为"。
 * 修复前运行本文件应全部失败（红），修复后全部通过（绿），
 * 以此验证问题真实存在且修复未引入回归。
 */
class ReviewRegressTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 问题2：where() 显式三参调用时 operator 未做白名单校验，
   * 恶意运算符字符串会被 SqlBuilder 直接内插进 SQL（注入风险）。
   * 期望：非法运算符在构建阶段即抛 InvalidArgumentException。
   */
  public function testWhereRejectsOperatorOutsideWhitelist(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('运算符');
    $query->where('id', '= 1 OR 1=1 --', 'x');
  }

  /**
   * 问题3：WhereGroup::parsing() 中数组条件的第 4 项 connector
   * 未做 AND/OR 白名单校验，可注入任意 SQL 片段。
   * 期望：非法连接符在解析阶段即抛 InvalidArgumentException。
   */
  public function testWheresRejectsConnectorOutsideWhitelist(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('连接符');
    $query->wheres([['id', '=', 1, 'XOR evil']]);
  }

  /**
   * 问题4：配置 database.channels 为空时 $defaultChannel（readonly）不会初始化，
   * 之后通过 addChannel() 动态注册通道再调用 channel() 会因访问
   * 未初始化的 readonly 属性抛出 Error。
   * 期望：动态注册后可正常获取默认通道。
   */
  public function testChannelResolvesAfterDynamicAddWithEmptyConfig(): void
  {
    // 匿名配置类让所有 database.* 配置返回空，模拟未配置通道的场景
    $config = new class extends Config {
      public function __construct()
      {
        // 跳过父级文件加载，仅用于满足 DbManager 构造签名
      }

      public function get(?string $name = null, mixed $default = null): mixed
      {
        return $default;
      }
    };
    $logManager = App::factory()->make(\Viswoole\Log\LogManager::class);
    $manager = new DbManager($config, $logManager);
    $manager->addChannel('runtime', new FakeChannel(0));
    // 修复前：读取未初始化的 readonly 属性抛 Error；修复后：回退到首个已注册通道
    $channel = $manager->channel();
    self::assertInstanceOf(Channel::class, $channel);
    self::assertTrue($manager->hasChannel('runtime'));
  }

  /**
   * 问题5：读写分离时 put() 依赖协程上下文中记录的池索引，
   * 事务内触发 getTableColumns()（直接 pop/put）会清除该索引，
   * 导致事务提交归还连接时索引为 null 而引发 Fatal Error。
   * 期望：索引缺失时 put() 能安全降级归还，不抛 Error。
   */
  public function testPutFallsBackWhenPoolIndexMissing(): void
  {
    $channel = new PDOChannel(
      type: DriverType::MYSQL,
      host: ['read' => ['127.0.0.1'], 'write' => ['127.0.0.1']],
    );
    $proxy = new PDOProxy('sqlite::memory:');
    // 确保当前协程上下文没有池索引（模拟被 getTableColumns 清除后的状态）
    \Viswoole\Core\Coroutine\Context::remove('$_pdo_current_index');
    // 修复前：对 null 索引取下标触发 "Call to a member function put() on null" Error
    $channel->put($proxy);
    self::assertTrue(true, '索引缺失时 put() 应安全降级而不抛 Error');
  }

  /**
   * 问题6：空集合调用 delete()/update() 时 getPks() 返回整数 0，
   * 传入 whereIn() 后被 empty() 误判抛出误导性异常，
   * 与文档承诺的"失败返回 0"不符。
   * 期望：空集合删除/更新直接返回 0。
   */
  public function testEmptyCollectionDeleteReturnsZero(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $collection = new Collection($query, []);
    self::assertSame(0, $collection->delete());
  }

  /**
   * 问题6（续）：空集合 update 同样应返回 0 而非抛异常
   */
  public function testEmptyCollectionUpdateReturnsZero(): void
  {
    $query = (new FakeChannel(0))->table('users');
    $collection = new Collection($query, []);
    self::assertSame(0, $collection->update(['name' => 'x']));
  }

  /**
   * 问题7：__destruct() 无保护调用 rollBack()，
   * 连接已失效时析构阶段抛出异常导致致命错误。
   * 期望：析构回滚失败时吞掉异常（析构阶段不应向外抛）。
   */
  public function testDestructSwallowsRollBackFailure(): void
  {
    run(function (): void {
      $manager = ConnectManager::factory();
      $manager->start();
      // 塞入一个 rollBack 必然失败的坏连接，模拟连接已失效
      $manager->pop(new BadRollBackChannel(), 'write');
      // 修复前：显式触发析构逻辑会抛出 PDOException；修复后：异常被吞掉
      $manager->__destruct();
      self::assertTrue(true, '析构阶段连接失效不应向外抛异常');
    });
  }

  /**
   * 问题8：getTableColumns() 查询不存在的表时，
   * ERRMODE_EXCEPTION 下裸抛 PDOException 而非约定的 DbException，
   * 且原 `if (!$statement)` 分支在 false 上调用 errorInfo() 自身即会报错。
   * 期望：包装为携带 SQL 信息的 DbException 抛出。
   */
  public function testGetTableColumnsWrapsErrorAsDbException(): void
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_review_test_' . uniqid() . '.sqlite';
    $channel = new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
    try {
      // withoutColumns 触发表结构查询，表不存在时应抛 DbException
      $this->expectException(DbException::class);
      $channel->table('table_not_exist')->withoutColumns('x')->getArray();
    } finally {
      @unlink($tmpDb);
    }
  }

  /**
   * 问题10：__call() 异常消息把方法名误写为通道名。
   * 期望：消息明确指出通道的哪个方法不存在。
   */
  public function testMagicCallExceptionMessageMentionsMethod(): void
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('msg_check', new FakeChannel(0));
    try {
      $manager->notExistMethod();
      self::fail('调用不存在的方法应抛出异常');
    } catch (\InvalidArgumentException $e) {
      self::assertStringContainsString('notExistMethod', $e->getMessage());
      self::assertStringNotContainsString('数据库通道 notExistMethod', $e->getMessage());
    }
  }

  /**
   * 问题1：with() 多关联并发查询时，子协程对共享 $data 的整体赋值
   * 相互覆盖（RelationQuery::query 传值 + 闭包 &$data），
   * 后完成的协程会用旧拷贝覆盖先完成协程的关联数据。
   * 期望：两个关联数据都被完整填充。
   */
  public function testWithMultipleRelationsKeepsAllRelationData(): void
  {
    $rows = null;
    run(function () use (&$rows): void {
      $db = App::factory()->make(DbManager::class);
      // 关闭调试输出：Output::echo 会关闭输出缓冲，导致 PHPUnit 报 risky
      $db->setDebug(false);
      // 三个通道分别服务主表与两个关联表，B(0.01s) 先完成、A(0.02s) 后完成，
      // 修复前 A 的整体赋值会用旧拷贝覆盖掉 B 已填充的关联数据
      $db->addChannel('review_main', new SleepChannel($this->makeRowsStatement([['id' => 1], ['id' => 2]]), 0.0));
      $db->addChannel('review_rel_a', new SleepChannel($this->makeRowsStatement([
        ['id' => 10, 'user_id' => 1],
        ['id' => 11, 'user_id' => 2],
      ]), 0.02));
      $db->addChannel('review_rel_b', new SleepChannel($this->makeRowsStatement([
        ['id' => 20, 'user_id' => 1],
        ['id' => 21, 'user_id' => 2],
      ]), 0.01));

      $rows = (new ReviewMainModel())->query->with(['profile', 'extra'])->getArray();
    });
    self::assertCount(2, $rows, '主表两行数据应全部返回');
    // 修复前：其中一个关联字段会因协程覆盖而缺失
    self::assertSame(10, $rows[0]['profile']['id'], '关联 profile 数据不应丢失');
    self::assertSame(20, $rows[0]['extra']['id'], '关联 extra 数据不应丢失');
    self::assertSame(11, $rows[1]['profile']['id']);
    self::assertSame(21, $rows[1]['extra']['id']);
  }

  /**
   * 构建一个返回指定行集的 SELECT 语句（内存 SQLite）
   *
   * @param array<int,array<string,mixed>> $rows 行数据
   */
  private function makeRowsStatement(array $rows): \PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE t (id INTEGER, user_id INTEGER)');
    foreach ($rows as $row) {
      $stmt = $pdo->prepare('INSERT INTO t (id, user_id) VALUES (?, ?)');
      $stmt->execute([$row['id'], $row['user_id'] ?? null]);
    }
    return $pdo->query('SELECT id, user_id FROM t ORDER BY id');
  }
}

/**
 * 带 IO 挂起点的通道替身
 *
 * execute() 中通过 Coroutine::sleep 模拟真实数据库的网络 IO 挂起，
 * 用于复现多协程并发查询下的数据竞争问题。
 */
class SleepChannel extends Channel
{
  public function __construct(
    private readonly \PDOStatement $statement,
    private readonly float $delaySeconds
  ) {}

  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): \Swoole\Database\PDOStatementProxy|\PDOStatement|int|string {
    if ($this->delaySeconds > 0) Coroutine::sleep($this->delaySeconds);
    return $this->statement;
  }

  public function pop(string $type): mixed
  {
    return null;
  }

  public function put(mixed $connect): void {}

  public function build(Options $options): Raw
  {
    return new Raw('SELECT id, user_id FROM t');
  }
}

/**
 * pop 返回 rollBack 必然失败的坏连接，用于验证析构保护
 */
class BadRollBackChannel extends Channel
{
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
    // rollDB 失效连接：beginTransaction 正常，rollBack 抛 PDOException
    return new class('sqlite::memory:') extends PDO {
      public function rollBack(): bool
      {
        throw new PDOException('server has gone away');
      }
    };
  }

  public function put(mixed $connect): void {}

  public function build(Options $options): Raw
  {
    return new Raw('');
  }
}

/**
 * 问题1 测试用主模型：绑定三个测试通道
 */
class ReviewMainModel extends Model
{
  protected string $table = 'main';
  protected string $pk = 'id';
  protected ?string $channelName = 'review_main';

  public function profile(): RelationQuery
  {
    return $this->hasOne(ReviewRelAModel::class, 'user_id', 'id');
  }

  public function extra(): RelationQuery
  {
    return $this->hasOne(ReviewRelBModel::class, 'user_id', 'id');
  }
}

class ReviewRelAModel extends Model
{
  protected string $table = 'rel_a';
  protected string $pk = 'id';
  protected ?string $channelName = 'review_rel_a';
}

class ReviewRelBModel extends Model
{
  protected string $table = 'rel_b';
  protected string $pk = 'id';
  protected ?string $channelName = 'review_rel_b';
}
