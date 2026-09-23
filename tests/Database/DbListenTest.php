<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\DbManager;
use Viswoole\Database\Query\RunInfo;
use Viswoole\Log\LogManager;

/**
 * database.listen 全局查询监听器测试
 *
 * 覆盖：每条查询触发、RunInfo 感知通道名与读写路由、容器 DI 形态、
 * 异常兜底不传播、非法配置抛出。测试自建 DbManager 并临时替换容器
 * 单例，结束后还原，避免污染其他用例。
 */
class DbListenTest extends TestCase
{
  /**
   * 构建带监听器的 DbManager 并临时替换容器单例
   *
   * @param Closure|string|array|null $listener database.listen 监听器
   * @return array{0:DbManager,1:DbManager,2:mixed} [测试用管理器, 容器原管理器, 监听器原值]
   */
  private function makeManager(Closure|string|array|null $listener): array
  {
    $config = App::factory()->make(Config::class);
    // 必须在 set 之前捕获原值，restore 才能真正还原，避免测试闭包残留共享配置
    $originalListener = $config->get('database.listen');
    $config->set('database.listen', $listener);
    $manager = new DbManager($config, new FakeDbListenLogManager());
    // 关闭调试输出：替身日志管理器未初始化真实通道，且避免 echo 触发 PHPUnit risky
    $manager->setDebug(false);
    $manager->addChannel('default', $this->makeSqliteChannel());
    // 查询经 Crud::setRunInfo → Db 门面触发 saveDebugInfo，
    // 必须替换容器单例，监听器才会随真实查询链路被触发
    $original = App::factory()->make(DbManager::class);
    App::factory()->bind(DbManager::class, $manager);
    return [$manager, $original, $originalListener];
  }

  /**
   * 还原容器单例与共享配置，避免污染其他用例
   */
  private function restore(DbManager $original, mixed $originalListener): void
  {
    App::factory()->bind(DbManager::class, $original);
    App::factory()->make(Config::class)->set('database.listen', $originalListener);
  }

  /**
   * 创建基于临时 SQLite 文件的通道（含 users 表与两行数据）
   */
  private function makeSqliteChannel(): PDOChannel
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_listen_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
    $pdo->exec("INSERT INTO users VALUES (1, 'a'), (2, 'b')");
    return new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
  }

  /**
   * 每条查询都应触发监听器，RunInfo 正确携带通道名与读写路由
   */
  public function testListenerReceivesRunInfoOnSelectAndWrite(): void
  {
    $records = [];
    [$manager, $original, $originalListener] = $this->makeManager(
      function (RunInfo $info) use (&$records): void {
        $records[] = $info;
      }
    );
    try {
      // 读查询：SELECT 路由为 read
      $manager->table('users')->where('id', 1)->get();
      // 写查询：INSERT 路由为 write
      $manager->table('users')->insert(['id' => 3, 'name' => 'c']);
      self::assertCount(2, $records);
      self::assertSame('default', $records[0]->channel);
      self::assertSame('read', $records[0]->route);
      self::assertSame('default', $records[1]->channel);
      self::assertSame('write', $records[1]->route);
      // 耗时统计始终存在且非负
      self::assertGreaterThanOrEqual(0, $records[0]->time['cost_time_ms']);
      self::assertStringContainsString('SELECT', $records[0]->sql->toString());
    } finally {
      $this->restore($original, $originalListener);
    }
  }

  /**
   * 监听器为 [类名, 方法名] 数组时应经容器依赖注入调用（类自动实例化）
   */
  public function testArrayClassMethodListenerResolvedByContainer(): void
  {
    DbListenStubHandler::$records = [];
    [, $original, $originalListener] = $this->makeManager([DbListenStubHandler::class, 'handle']);
    try {
      $manager = App::factory()->make(DbManager::class);
      $manager->table('users')->get();
      self::assertCount(1, DbListenStubHandler::$records);
      self::assertSame('default', DbListenStubHandler::$records[0]->channel);
    } finally {
      $this->restore($original, $originalListener);
    }
  }

  /**
   * 监听器抛出异常时应兜底记录 error 且不影响查询结果
   */
  public function testListenerExceptionDoesNotBreakQuery(): void
  {
    [$manager, $original, $originalListener] = $this->makeManager(
      function (RunInfo $_info): void {
        throw new RuntimeException('listener boom');
      }
    );
    try {
      $rows = $manager->table('users')->where('id', 1)->getArray();
      // 查询正常返回结果，异常被兜底
      self::assertSame([['id' => 1, 'name' => 'a']], $rows);
      // 兜底 error 日志已记录
      $logManager = $this->readManagerLogManager($manager);
      self::assertCount(1, $logManager->errorRecords);
      self::assertStringContainsString('listener boom', $logManager->errorRecords[0][0]);
    } finally {
      $this->restore($original, $originalListener);
    }
  }

  /**
   * 监听器连续失败达到阈值后应熔断：后续查询不再调用，仅记录一次停用告警
   */
  public function testListenerTripsAfterConsecutiveFailures(): void
  {
    $calls = 0;
    [$manager, $original, $originalListener] = $this->makeManager(
      function (RunInfo $_info) use (&$calls): void {
        $calls++;
        throw new RuntimeException('listener boom');
      }
    );
    try {
      $logManager = $this->readManagerLogManager($manager);
      // 连续失败 3 次：每次均调用监听器并记录失败日志，第 3 次追加停用告警
      for ($i = 0; $i < 3; $i++) {
        $manager->table('users')->getArray();
      }
      self::assertSame(3, $calls);
      self::assertCount(4, $logManager->errorRecords);
      self::assertStringContainsString(
        '连续失败 3 次', $logManager->errorRecords[3][0]
      );
      // 熔断后再查询：监听器不再被调用，也不产生新的 error 日志
      $manager->table('users')->getArray();
      self::assertSame(3, $calls);
      self::assertCount(4, $logManager->errorRecords);
    } finally {
      $this->restore($original, $originalListener);
    }
  }

  /**
   * 失败后成功应重置连续计数：失败→成功→失败的交替模式不触发熔断
   *
   * 对照：若无归零逻辑，失败2次+成功+失败2次累计 4 次，第 5 次查询（第 4 次
   * 失败）即熔断；有归零逻辑则连续计数始终无法达到 3。
   */
  public function testSuccessResetsConsecutiveFailureCount(): void
  {
    $calls = 0;
    $shouldFail = true;
    [$manager, $original, $originalListener] = $this->makeManager(
      function (RunInfo $_info) use (&$calls, &$shouldFail): void {
        $calls++;
        if ($shouldFail) throw new RuntimeException('flaky boom');
      }
    );
    try {
      $logManager = $this->readManagerLogManager($manager);
      // 失败 2 次 → 成功（计数归零）→ 失败 1 次
      $manager->table('users')->getArray();
      $manager->table('users')->getArray();
      $shouldFail = false;
      $manager->table('users')->getArray();
      $shouldFail = true;
      $manager->table('users')->getArray();
      self::assertSame(4, $calls);
      self::assertCount(3, $logManager->errorRecords);
      // 未熔断：第 5 次查询（再失败）监听器依然被调用，且无停用告警
      $manager->table('users')->getArray();
      self::assertSame(5, $calls);
      self::assertCount(4, $logManager->errorRecords);
      self::assertStringNotContainsString('已停用', $logManager->errorRecords[3][0]);
    } finally {
      $this->restore($original, $originalListener);
    }
  }

  /**
   * 原生 Db::query/execute 也应触发监听器，覆盖范围与查询构建器一致
   */
  public function testNativeQueryTriggersListener(): void
  {
    $records = [];
    [$manager, $original, $originalListener] = $this->makeManager(
      function (RunInfo $info) use (&$records): void {
        $records[] = $info;
      }
    );
    try {
      // 原生查询（SELECT → read 路由）
      $manager->query('SELECT * FROM users WHERE id = ?', [1]);
      // 原生写入（INSERT → write 路由）
      $manager->execute('INSERT INTO users (id, name) VALUES (?, ?)', [4, 'd']);
      self::assertCount(2, $records);
      self::assertSame('default', $records[0]->channel);
      self::assertSame('read', $records[0]->route);
      self::assertStringContainsString('SELECT', $records[0]->sql->toString());
      self::assertSame('default', $records[1]->channel);
      self::assertSame('write', $records[1]->route);
      // 原生查询返回值不受监听影响（初始 2 行 + 原生写入 1 行）
      $rows = $manager->query('SELECT * FROM users');
      self::assertCount(3, $rows);
    } finally {
      $this->restore($original, $originalListener);
    }
  }

  /**
   * 反射读取管理器内部的日志管理器替身
   */
  private function readManagerLogManager(DbManager $manager): FakeDbListenLogManager
  {
    $prop = new ReflectionProperty(DbManager::class, 'logManager');
    return $prop->getValue($manager);
  }

  /**
   * database.listen 为非法形态（非闭包/字符串/数组）时应抛出明确异常
   */
  public function testInvalidListenConfigThrows(): void
  {
    $config = App::factory()->make(Config::class);
    $original = $config->get('database.listen');
    $config->set('database.listen', 123);
    try {
      new DbManager($config, new FakeDbListenLogManager());
      self::fail('非法 listen 配置应抛出 InvalidArgumentException');
    } catch (\InvalidArgumentException $e) {
      self::assertStringContainsString('database.listen', $e->getMessage());
    } finally {
      $config->set('database.listen', $original);
    }
  }
}

/**
 * 查询监听器存根，验证 [类名, 方法名] 形态经容器 DI 调用
 */
class DbListenStubHandler
{
  /**
   * @var array<int,RunInfo> 已接收的查询运行信息
   */
  public static array $records = [];

  /**
   * 记录查询运行信息
   */
  public function handle(RunInfo $info): void
  {
    self::$records[] = $info;
  }
}

/**
 * 捕获 error 记录的日志管理器测试替身
 */
class FakeDbListenLogManager extends LogManager
{
  /**
   * @var array<int,array{0:string,1:array}> error 级别日志记录
   */
  public array $errorRecords = [];

  /**
   * 跳过父类构造：替身仅捕获方法调用，无需初始化真实日志通道
   */
  public function __construct()
  {
  }

  /**
   * 捕获 error 级别日志，不落盘
   */
  public function error(Stringable|string $message, array $context = []): void
  {
    $this->errorRecords[] = [(string)$message, $context];
  }
}
