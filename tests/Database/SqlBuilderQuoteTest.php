<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\DbManager;
use Viswoole\Database\Query\Options;

/**
 * SqlBuilder 标识符包裹（quote）安全测试
 *
 * 验证列名/表名/排序字段等标识符在拼接进 SQL 前的合法性校验：
 * 此前 quote 对含空格/括号/引号的输入"原样放行"，恶意标识符
 * （如排序参数被注入用户输入时）可直接拼入 SQL 构成注入
 */
class SqlBuilderQuoteTest extends TestCase
{
  /** @var PDOChannel|null 测试用通道 */
  private ?PDOChannel $channel = null;

  /** @var string|null 临时 SQLite 文件 */
  private ?string $tmpDb = null;

  protected function setUp(): void
  {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $this->tmpDb = sys_get_temp_dir() . '/viswoole_quote_' . uniqid() . '.sqlite';
    $this->channel = new PDOChannel(type: DriverType::SQLite, database: $this->tmpDb);
    App::factory()->make(DbManager::class)->setDebug(false);
  }

  protected function tearDown(): void
  {
    if ($this->tmpDb !== null && is_file($this->tmpDb)) unlink($this->tmpDb);
    $this->channel = null;
    $this->tmpDb = null;
  }

  /**
   * 构建携带指定 WHERE 条件的 SELECT 语句
   *
   * @param string $column 条件列名
   * @param string $operator 运算符
   * @param mixed $value 条件值
   * @return string 构建后的 SQL
   */
  private function buildSelect(string $column, string $operator = '=', mixed $value = 'x'): string
  {
    $options = new Options('users', 'id');
    $options->type = 'select';
    $options->where[] = [
      'column' => $column, 'operator' => $operator, 'value' => $value, 'connector' => 'AND'
    ];
    return $this->channel->build($options)->sql;
  }

  /**
   * 测试含注入载荷的列名在构建期被拒绝
   *
   * @return void
   */
  public function testRejectsInjectionPayloadAsColumn(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->buildSelect('id) UNION SELECT password FROM users--');
  }

  /**
   * 测试含空格/反引号/引号的标识符在构建期被拒绝
   *
   * @return void
   */
  public function testRejectsIllegalCharactersAsColumn(): void
  {
    foreach (['a b', 'id`', "col'", 'col"'] as $column) {
      try {
        $this->buildSelect($column);
        self::fail("标识符 '{$column}' 应被拒绝");
      } catch (\InvalidArgumentException) {
        // 期望行为：构建期即拒绝
      }
    }
    self::assertTrue(true);
  }

  /**
   * 测试合法标识符（含点号分段与星号）正常构建
   *
   * @return void
   */
  public function testAcceptsLegalIdentifiers(): void
  {
    $sql = $this->buildSelect('users.id');
    self::assertStringContainsString('WHERE `users`.`id` = ?', $sql);
    $options = new Options('users', 'id');
    $options->type = 'select';
    $options->columns['*'] = null;
    self::assertSame(
      'SELECT * FROM `users`',
      $this->channel->build($options)->sql
    );
  }

  /**
   * 测试三参数 where 显式传入数组值 + 非法运算符在构建期报错
   *
   * @return void
   */
  public function testRejectsArrayValueWithScalarOperator(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('不支持数组值');
    $this->buildSelect('id', '=', [1, 2]);
  }

  /**
   * 测试聚合字段参数的字符集校验
   *
   * @return void
   */
  public function testAggregateColumnCharsetValidation(): void
  {
    $query = $this->channel->table('users');
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('无效的聚合字段');
    $query->count('id) ; DROP TABLE users');
  }

  /**
   * 测试 join 表名支持 AS 别名语法
   *
   * @return void
   */
  public function testJoinTableWithAlias(): void
  {
    $query = $this->channel->table('users');
    $sql = $query->join('orders AS o', 'users.id', 'o.user_id')->toRaw()->getArray()->sql ?? '';
    self::assertStringContainsString('INNER JOIN `orders` AS `o` ON', $sql);
  }
}
