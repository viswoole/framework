<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Channel\PDO\PDOConfig;
use Viswoole\Database\Channel\PDO\PDOPool;

/**
 * PDO 通道时区对齐测试
 *
 * 验证连接创建时的时区注入行为：
 * 1. 默认（null）：连接时区对齐应用时区（MySQL 经 INIT_COMMAND 注入 UTC 偏移）；
 * 2. 自定义字符串：使用指定时区（支持命名时区自动转偏移）；
 * 3. 空串（''）：不做任何时区设置（逃生口，维持服务端默认）；
 * 4. MySQL 用户已设置 INIT_COMMAND 时追加而非覆盖；
 * 5. PostgreSQL 经 DSN options 注入命名时区；
 * 6. SQLite 等无时区概念的驱动跳过。
 */
class PdoTimezoneTest extends TestCase
{
  /**
   * 获取通道连接池内 PDOConfig 的时区解析结果与 INIT_COMMAND（反射 createDSN/createConnection 链路）
   */
  public function testDefaultTimezoneAlignsToAppTimezone(): void
  {
    $appTimezone = date_default_timezone_get(); // 如 Asia/Shanghai
    $expectedOffset = (new DateTimeZone($appTimezone))
      ->getOffset(new \DateTimeImmutable('now', new DateTimeZone('UTC'))) >= 0 ? '+' : '-';
    $channel = new PDOChannel(type: DriverType::MYSQL, database: 'test');
    $command = $this->extractMysqlInitCommand($channel);
    self::assertNotNull($command, '默认配置应注入 MySQL INIT_COMMAND 时区语句');
    self::assertStringStartsWith(
      "SET time_zone = '{$expectedOffset}",
      $command,
      '默认应对齐应用时区并以 UTC 偏移格式注入'
    );
  }

  /**
   * 场景2：自定义命名时区自动转换为 UTC 偏移注入
   */
  public function testNamedTimezoneConvertedToOffset(): void
  {
    $channel = new PDOChannel(type: DriverType::MYSQL, database: 'test', timezone: 'Asia/Shanghai');
    $command = $this->extractMysqlInitCommand($channel);
    self::assertSame("SET time_zone = '+08:00'", $command);
  }

  /**
   * 场景2b：直接传偏移字符串原样注入
   */
  public function testOffsetTimezonePassedThrough(): void
  {
    $channel = new PDOChannel(type: DriverType::MYSQL, database: 'test', timezone: '+05:30');
    self::assertSame(
      "SET time_zone = '+05:30'",
      $this->extractMysqlInitCommand($channel)
    );
  }

  /**
   * 场景3：空串不做任何时区设置（维持服务端默认）
   */
  public function testEmptyStringDisablesTimezoneInjection(): void
  {
    $channel = new PDOChannel(type: DriverType::MYSQL, database: 'test', timezone: '');
    self::assertNull($this->extractMysqlInitCommand($channel));
  }

  /**
   * 场景4：用户已设置 INIT_COMMAND 时追加而非覆盖
   */
  public function testUserInitCommandIsAppendedNotOverwritten(): void
  {
    $channel = new PDOChannel(
      type: DriverType::MYSQL,
      database: 'test',
      options: [\PDO\Mysql::ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'],
      timezone: 'Asia/Shanghai'
    );
    $command = $this->extractMysqlInitCommand($channel);
    self::assertNotNull($command);
    self::assertStringContainsString('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', $command);
    self::assertStringContainsString("SET time_zone = '+08:00'", $command);
  }

  /**
   * 场景4b：用户 INIT_COMMAND 已含时区语句时尊重用户设置，不再追加
   */
  public function testExistingTimezoneInInitCommandIsRespected(): void
  {
    $channel = new PDOChannel(
      type: DriverType::MYSQL,
      database: 'test',
      options: [\PDO\Mysql::ATTR_INIT_COMMAND => 'SET NAMES utf8mb4; SET time_zone = \'+00:00\''],
      timezone: 'Asia/Shanghai'
    );
    $command = $this->extractMysqlInitCommand($channel);
    self::assertSame(
      'SET NAMES utf8mb4; SET time_zone = \'+00:00\'',
      $command,
      '用户已自行设置时区时不应追加框架的时区语句'
    );
  }

  /**
   * 场景5：PostgreSQL 经 DSN 注入命名时区
   */
  public function testPostgresTimezoneInjectedViaDsn(): void
  {
    $pool = $this->makePool(DriverType::POSTGRESQL, 'Asia/Shanghai');
    $dsn = $this->invokeCreateDSN($pool);
    self::assertStringContainsString("options='-c TimeZone=Asia/Shanghai'", $dsn);
  }

  /**
   * 场景6：SQLite 跳过时区注入（无时区概念，不产生任何附加语句）
   */
  public function testSqliteSkipsTimezoneInjection(): void
  {
    $tmpDb = sys_get_temp_dir() . '/viswoole_tz_' . uniqid() . '.sqlite';
    (new PDO('sqlite:' . $tmpDb))->exec('CREATE TABLE t (id INTEGER)');
    $channel = new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
    // 不抛异常且无 INIT_COMMAND 即为跳过
    self::assertNull($this->extractMysqlInitCommand($channel));
    @unlink($tmpDb);
  }

  /* ------------------------------------------------------------------ */
  /* 辅助方法                                                            */
  /* ------------------------------------------------------------------ */

  /**
   * 从通道反射取得连接池，再模拟 createConnection 的 options 组装链路提取 MySQL INIT_COMMAND
   */
  private function extractMysqlInitCommand(PDOChannel $channel): ?string
  {
    $pool = $this->getPool($channel);
    if ($pool->getConfig()->type !== DriverType::MYSQL) return null;
    // 与 createConnection 等价的 options 组装：拷贝配置项后执行时区注入
    $options = $pool->getConfig()->options;
    $method = new ReflectionMethod(PDOPool::class, 'applyMysqlTimezone');
    $method->invokeArgs($pool, [&$options]);
    return $options[\PDO\Mysql::ATTR_INIT_COMMAND] ?? null;
  }

  /**
   * 反射读取通道的单库连接池
   */
  private function getPool(PDOChannel $channel): PDOPool
  {
    $prop = new \ReflectionProperty(PDOChannel::class, 'pool');
    $pool = $prop->getValue($channel);
    self::assertInstanceOf(PDOPool::class, $pool, '测试通道应为单库结构');
    return $pool;
  }

  /**
   * 构建指定驱动的连接池
   */
  private function makePool(DriverType $type, ?string $timezone): PDOPool
  {
    $channel = new PDOChannel(type: $type, database: 'test', timezone: $timezone);
    return $this->getPool($channel);
  }

  /**
   * 反射调用 createDSN
   */
  private function invokeCreateDSN(PDOPool $pool): string
  {
    $method = new ReflectionMethod(PDOPool::class, 'createDSN');
    return $method->invoke($pool, $pool->getConfig()->type);
  }
}
