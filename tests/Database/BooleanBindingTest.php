<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\DbManager;

/**
 * 布尔/类型化绑定参数回归测试
 *
 * 覆盖 execute 绑定路径的类型映射：修复前所有绑定值按 PARAM_STR 传递，
 * 布尔 false 在落库时被序列化为空串 ''（而非 0），导致布尔字段写入与
 * 条件匹配异常。修复后经 bindValue 按 PHP 类型显式声明绑定类型。
 *
 * SQLite 上绑定 false：修复前落库为 ''，修复后落库为 0，
 * 该差异即红→绿断言的依据。
 */
class BooleanBindingTest extends TestCase
{
  /** @var string 临时 SQLite 文件路径 */
  private string $tmpDb;
  /** @var PDO 直连句柄，用于读取落库的原始值（绕开框架查询层） */
  private PDO $rawPdo;

  protected function setUp(): void
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $this->tmpDb = sys_get_temp_dir() . '/viswoole_bool_bind_' . uniqid() . '.sqlite';
    $this->rawPdo = new PDO('sqlite:' . $this->tmpDb);
    $this->rawPdo->exec('CREATE TABLE shop_options (product_id INTEGER, is_enabled BOOLEAN, label TEXT)');
    // 关闭调试输出：避免 SQL 日志打印触发 PHPUnit risky
    App::factory()->make(DbManager::class)->setDebug(false);
  }

  protected function tearDown(): void
  {
    if (is_file($this->tmpDb)) {
      @unlink($this->tmpDb);
    }
  }

  /**
   * 创建指向临时库的框架通道，并预置一行 product_id=1 的数据
   */
  private function makeChannel(): PDOChannel
  {
    return new PDOChannel(type: DriverType::SQLite, database: $this->tmpDb);
  }

  /**
   * 读取指定行的 is_enabled 原始落库值
   *
   * @return int|string|null 落库后的原始值（int 或空串等）
   */
  private function fetchRawFlag(int|string $productId): int|string|null
  {
    $stmt = $this->rawPdo->prepare('SELECT is_enabled FROM shop_options WHERE product_id = ?');
    $stmt->execute([$productId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
  }

  /**
   * update 传布尔 false 应落库为 0 而非空串
   */
  public function testUpdateBooleanFalseStoresIntZero(): void
  {
    $channel = $this->makeChannel();
    $channel->table('shop_options')->insert(['product_id' => 1, 'is_enabled' => true, 'label' => 'a']);
    $affected = $channel->table('shop_options')
      ->where('product_id', 1)
      ->update(['is_enabled' => false]);
    self::assertSame(1, $affected);
    self::assertSame(0, $this->fetchRawFlag(1));
  }

  /**
   * update 传布尔 true 应落库为 1
   */
  public function testUpdateBooleanTrueStoresIntOne(): void
  {
    $channel = $this->makeChannel();
    $channel->table('shop_options')->insert(['product_id' => 1, 'is_enabled' => false, 'label' => 'a']);
    $channel->table('shop_options')
      ->where('product_id', 1)
      ->update(['is_enabled' => true]);
    self::assertSame(1, $this->fetchRawFlag(1));
  }

  /**
   * where 传布尔值应精确命中对应布尔状态的行
   */
  public function testWhereBooleanMatchesStoredBoolRows(): void
  {
    $channel = $this->makeChannel();
    $channel->table('shop_options')->insert(['product_id' => 1, 'is_enabled' => true, 'label' => 'on']);
    $channel->table('shop_options')->insert(['product_id' => 2, 'is_enabled' => false, 'label' => 'off']);
    // 修复前 is_enabled 落库为 ''，false 条件命中的是脏数据行而非 false 行
    self::assertSame(2, $channel->table('shop_options')
      ->where('is_enabled', false)
      ->value('product_id'));
    self::assertSame(1, $channel->table('shop_options')
      ->where('is_enabled', true)
      ->value('product_id'));
  }

  /**
   * null 绑定应落库为 SQL NULL
   */
  public function testUpdateNullStoresSqlNull(): void
  {
    $channel = $this->makeChannel();
    $channel->table('shop_options')->insert(['product_id' => 1, 'is_enabled' => true, 'label' => 'a']);
    $stmt = $this->rawPdo->prepare(
      'SELECT COUNT(*) FROM shop_options WHERE product_id = 1 AND is_enabled IS NULL'
    );
    // 经框架 update 写入 null
    $channel->table('shop_options')
      ->where('product_id', 1)
      ->update(['is_enabled' => null]);
    $stmt->execute();
    self::assertSame(1, (int)$stmt->fetchColumn());
    self::assertNull($this->fetchRawFlag(1));
  }

  /**
   * 混合类型批量写入：各类型值经绑定映射后均正确落库
   */
  public function testMixedTypeBatchInsertStoresAllTypes(): void
  {
    $channel = $this->makeChannel();
    $channel->table('shop_options')->insert([
      ['product_id' => 1, 'is_enabled' => true, 'label' => 'text'],
      ['product_id' => 2, 'is_enabled' => false, 'label' => null],
      ['product_id' => 3, 'is_enabled' => true, 'label' => '3.14'],
    ]);
    $stmt = $this->rawPdo->prepare(
      'SELECT is_enabled, label FROM shop_options ORDER BY product_id'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    self::assertCount(3, $rows);
    // 布尔落库为 1/0，null 保持 NULL，字符串与数值原样
    self::assertSame(1, (int)$rows[0]['is_enabled']);
    self::assertSame('text', $rows[0]['label']);
    self::assertSame(0, (int)$rows[1]['is_enabled']);
    self::assertNull($rows[1]['label']);
    self::assertSame('3.14', $rows[2]['label']);
  }

  /**
   * 命名占位符绑定：Raw SQL 携带字符串键（含布尔值）经执行层正确落库与查询
   *
   * bindStatementValues 对字符串键原样传递给 bindValue（PDO 兼容带/不带
   * 冒号的命名占位符），本用例锁定该契约——Raw 的命名 bindings 经
   * SqlBuilder 合并后真实进入此路径。
   */
  public function testNamedPlaceholderBindingWithBoolValue(): void
  {
    $channel = $this->makeChannel();
    $channel->table('shop_options')->insert(['product_id' => 1, 'is_enabled' => true, 'label' => 'a']);
    // 命名占位符 UPDATE：布尔 false 应落库为 0 而非空串
    $channel->execute(
      'UPDATE shop_options SET is_enabled = :flag WHERE product_id = :pid',
      ['flag' => false, 'pid' => 1]
    );
    self::assertSame(0, $this->fetchRawFlag(1));
    // 命名占位符 SELECT：布尔条件精确命中
    $stmt = $channel->execute(
      'SELECT product_id FROM shop_options WHERE is_enabled = :flag',
      ['flag' => false]
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    self::assertSame([['product_id' => 1]], $rows);
  }
}
