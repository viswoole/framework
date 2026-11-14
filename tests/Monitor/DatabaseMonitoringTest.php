<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Exception\DbException;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 数据库监控测试
 *
 * 使用 SQLite 验证生产 SQL 执行边界，覆盖原生 SQL 与失败操作。
 */
final class DatabaseMonitoringTest extends TestCase
{
  /** @var string 每个测试独立的 SQLite 临时数据库文件 */
  private string $database;

  protected function setUp(): void
  {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) { self::markTestSkipped('需要 PDO SQLite'); }
    $this->database = tempnam(sys_get_temp_dir(), 'monitor_sqlite_');
  }

  protected function tearDown(): void
  {
    TraceContext::clear();
    if (isset($this->database)) { unlink($this->database); }
  }

  /**
   * SQL 执行记录通道、读写路由与脱敏绑定；失败操作标记 success=false
   */
  public function testSqlExecutionRecordsChannelBindingsAndFailures(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $channel = new PDOChannel(type: DriverType::SQLite, database: $this->database);
    $channel->name = 'monitor_sqlite';
    $statement = $channel->execute('SELECT ? AS value', ['private']);
    self::assertSame('private', $statement->fetchColumn());
    $statement->closeCursor();
    try {
      $channel->execute('SELECT * FROM missing_monitor_table');
      self::fail('无效查询应抛出异常');
    } catch (DbException) {
      $operations = $trace->finish()['operations'];
      self::assertCount(2, $operations);
      self::assertSame('monitor_sqlite', $operations[0]['channel']);
      self::assertSame('read', $operations[0]['route']);
      self::assertSame('sha256:' . hash('sha256', 'SELECT ? AS value'), $operations[0]['sql']);
      self::assertSame(['[REDACTED]'], $operations[0]['bindings']);
      self::assertGreaterThanOrEqual(0, $operations[0]['duration_ms']);
      self::assertFalse($operations[1]['success']);
    }
  }

  /**
   * 开启 record_bindings 后位置参数记录原值，命名敏感参数仍被脱敏
   */
  public function testOptedInBindingsStillRedactNamedSensitiveFields(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'record_bindings' => true]), []);
    TraceContext::attach($trace);
    $channel = new PDOChannel(type: DriverType::SQLite, database: $this->database);
    $channel->execute('SELECT :password, :number', ['password' => 'private', 'number' => 3])->closeCursor();
    self::assertSame(['password' => '[REDACTED]', 'number' => 3], $trace->finish()['operations'][0]['bindings']);
  }

  /** @return void 带前导冒号的短命名绑定不改变执行，却必须隐藏日志值。 */
  public function testColonPrefixedShortBindingsAreRedacted(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'record_bindings' => true]), []);
    TraceContext::attach($trace);
    $channel = new PDOChannel(type: DriverType::SQLite, database: $this->database);
    $statement = $channel->execute('SELECT :pwd, :otp, :number', [':pwd' => 'private-password', ':otp' => 'private-code', ':number' => 3]);
    self::assertSame(['private-password', 'private-code', 3], $statement->fetch(\PDO::FETCH_NUM));
    $statement->closeCursor();
    self::assertSame([':pwd' => '[REDACTED]', ':otp' => '[REDACTED]', ':number' => 3], $trace->snapshot()->operations[0]->bindings);
  }

  /** @return void 原生 SQL 的字符串、数字和注释只能在明确开启原文记录后保留。 */
  public function testSqlTextRequiresExplicitOptIn(): void
  {
    $sql = "SELECT 'synthetic-private', 424242 /* synthetic-comment */";
    foreach ([false, true] as $canRecordSql) {
      $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'record_sql' => $canRecordSql]), []);
      TraceContext::attach($trace);
      $channel = new PDOChannel(type: DriverType::SQLite, database: $this->database);
      $statement = $channel->execute($sql);
      self::assertSame('synthetic-private', $statement->fetchColumn());
      $statement->closeCursor();
      self::assertSame($canRecordSql ? $sql : 'sha256:' . hash('sha256', $sql), $trace->snapshot()->operations[0]->sql);
    }
  }

  /**
   * 事务内普通查询记录实际写库路由
   *
   * 普通 SELECT 请求 read，但连接管理器在事务中使用 write，记录须反映实际选择。
   *
   * @return void
   */
  public function testTransactionSelectRecordsWriteRoute(): void
  {
    \Swoole\Coroutine\run(function (): void {
      $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
      TraceContext::attach($trace);
      $channel = new PDOChannel(type: DriverType::SQLite, database: $this->database);
      $manager = \Viswoole\Database\ConnectManager::factory();
      $manager->start();
      try {
        $channel->execute('SELECT 1')->closeCursor();
        $operation = $trace->finish()['operations'][0];
        self::assertSame('read', $operation['requested_route']);
        self::assertSame('write', $operation['route']);
      } finally {
        $manager->rollBack();
        TraceContext::clear();
      }
    });
  }

  /**
   * 粘滞连接记录实际写库路由
   *
   * 使用同一 SQLite 池模拟两个路由，不依赖真实主从数据库即可验证 sticky 接线。
   *
   * @return void
   */
  public function testStickySelectRecordsWriteRoute(): void
  {
    \Swoole\Coroutine\run(function (): void {
      $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
      TraceContext::attach($trace);
      $source = new PDOChannel(type: DriverType::SQLite, database: $this->database);
      $channel = (new \ReflectionClass(PDOChannel::class))->newInstanceWithoutConstructor();
      $channel->pool_timeout_time = 5;
      $channel->name = 'sticky_monitor';
      $property = new \ReflectionProperty(PDOChannel::class, 'pool');
      $pool = $property->getValue($source);
      $property->setValue($channel, ['read' => [$pool], 'write' => [$pool]]);
      \Viswoole\Core\Coroutine\Context::set('$_pdo_write_pool_index_sticky_monitor', 0);
      try {
        $channel->execute('SELECT 1')->closeCursor();
        self::assertSame('write', $trace->finish()['operations'][0]['route']);
      } finally {
        TraceContext::clear();
      }
    });
  }

}
