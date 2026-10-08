<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Override;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Exception\DataNotFoundException;
use Viswoole\Database\Facade\Db as DbFacade;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * 批次 1（查询构建层）加固回归测试
 *
 * 覆盖四类修复：
 *  1. 聚合方法返回类型契约——空表 SQL 聚合返回 NULL 行，旧实现会以 null 违反
 *     float|int 声明抛 TypeError，修复后应返回 null 表示"无数据"
 *  2. limit/offset 负数、一元运算符携带值、空条件分组、bool 运算符等边界守卫
 *  3. 异常文本脱敏——DataNotFoundException 的 SQL 不再合并绑定值（防敏感信息泄露）
 *  4. 克隆隔离——__clone 对 Raw 片段深拷贝，克隆体与原查询互不污染
 */
class QueryHardeningTest extends TestCase
{
  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志（避免 PHPUnit 标记 risky）
   *
   * @param callable $fn 无参回调，内部执行触发查询的链式调用
   */
  private function runQuietly(callable $fn): void
  {
    ob_start();
    try {
      $fn();
    } finally {
      ob_end_clean();
    }
  }

  /**
   * 创建聚合查询语句：指定行数的 users 表 + 单行聚合结果
   *
   * @param string $expr 聚合表达式，如 AVG(id)
   * @param string $alias 结果列别名，与聚合方法读取的键一致
   * @param int $rows 表中行数（0 时模拟空表，SQL 聚合返回 NULL）
   */
  private function makeAggregateStatement(string $expr, string $alias, int $rows): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
    for ($i = 1; $i <= $rows; $i++) {
      $pdo->exec("INSERT INTO users VALUES ($i, 'u$i')");
    }
    return $pdo->query("SELECT {$expr} AS {$alias} FROM users");
  }

  /**
   * 创建空表的 SELECT 语句（返回 0 行结果集）
   */
  private function makeEmptySelectStatement(): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
    return $pdo->query('SELECT * FROM users');
  }

  // ---------------------------------------------------------------- B1-01

  /**
   * 空表 sum() 应返回 null 而非以 null 违反类型声明抛 TypeError
   */
  public function testSumOnEmptyTableReturnsNull(): void
  {
    $channel = new FakeChannel($this->makeAggregateStatement('SUM(id)', 'sum', 0));
    $this->runQuietly(function () use ($channel) {
      self::assertNull($channel->table('users')->sum('id'));
    });
  }

  /**
   * 空表 avg() 应返回 null 而非以 null 违反类型声明抛 TypeError
   */
  public function testAvgOnEmptyTableReturnsNull(): void
  {
    $channel = new FakeChannel($this->makeAggregateStatement('AVG(id)', 'avg', 0));
    $this->runQuietly(function () use ($channel) {
      self::assertNull($channel->table('users')->avg('id'));
    });
  }

  /**
   * 非空表 avg() 应返回数值（整型列 AVG 在 MySQL 下返回 DECIMAL 字符串，
   * 修复后 aggregateQueries 对 is_numeric 值归一为 int|float）
   */
  public function testAvgOnIntColumnReturnsNumeric(): void
  {
    $channel = new FakeChannel($this->makeAggregateStatement('AVG(id)', 'avg', 2));
    $this->runQuietly(function () use ($channel) {
      $result = $channel->table('users')->avg('id');
      self::assertIsFloat($result);
      self::assertSame(1.5, $result);
    });
  }

  // ---------------------------------------------------------------- B1-04

  /**
   * limit(-1) 应在入口拒绝，而非生成 LIMIT -1 由数据库报语法错误
   */
  public function testNegativeLimitThrows(): void
  {
    $channel = new FakeChannel($this->makeEmptySelectStatement());
    $this->expectException(\InvalidArgumentException::class);
    $channel->table('users')->limit(-1);
  }

  /**
   * offset(-1) 应在入口拒绝
   */
  public function testNegativeOffsetThrows(): void
  {
    $channel = new FakeChannel($this->makeEmptySelectStatement());
    $this->expectException(\InvalidArgumentException::class);
    $channel->table('users')->offset(-1);
  }

  // ---------------------------------------------------------------- B1-05

  /**
   * 显式三参 where 携带 IS NULL 与比较值应拒绝，而非生成 "col IS NULL ?" 坏 SQL
   */
  public function testUnaryOperatorWithValueThrows(): void
  {
    $channel = new FakeChannel($this->makeEmptySelectStatement());
    $this->expectException(\InvalidArgumentException::class);
    $channel->table('users')->where('deleted_at', 'IS NULL', 'x');
  }

  // ---------------------------------------------------------------- B1-06

  /**
   * 空条件分组应拒绝，而非生成 "AND ()" 非法 SQL
   */
  public function testEmptyWhereGroupThrows(): void
  {
    $channel = new FakeChannel($this->makeEmptySelectStatement());
    $this->expectException(\InvalidArgumentException::class);
    $channel->table('users')->whereGroup([]);
  }

  // ---------------------------------------------------------------- B1-07

  /**
   * wheres 索引数组中的 bool 应被严格白名单拒绝（loose in_array 下 true 绕过）
   */
  public function testBoolOperatorRejectedInWheres(): void
  {
    $channel = new FakeChannel($this->makeEmptySelectStatement());
    $this->expectException(\InvalidArgumentException::class);
    $channel->table('users')->wheres([['id', true, 'x']]);
  }

  // ---------------------------------------------------------------- B1-12

  /**
   * find(allowEmpty: false) 空结果抛出的异常，其 SQL 不应包含绑定值（防敏感信息泄露）
   *
   * 通道替身返回带占位符与敏感绑定值的 Raw，修复前异常文本经 Raw::merge
   * 将 's3cret-绑定的密码' 内联进 SQL；修复后应保留占位符、省略绑定值。
   */
  public function testDataNotFoundExceptionSqlOmitsBindings(): void
  {
    $channel = new class ($this->makeEmptySelectStatement()) extends FakeChannel {
      #[Override]
      public function build(Options $options): Raw
      {
        return new Raw('SELECT * FROM `users` WHERE `name` = ?', ['s3cret-绑定的密码']);
      }
    };
    $this->runQuietly(function () use ($channel) {
      try {
        $channel->table('users')->find(1, allowEmpty: false);
        self::fail('空结果且不允许空时应抛出 DataNotFoundException');
      } catch (DataNotFoundException $e) {
        self::assertStringNotContainsString('s3cret-绑定的密码', $e->getMessage());
        $sql = $e->getSql() ?? '';
        self::assertStringNotContainsString('s3cret-绑定的密码', $sql);
        self::assertStringContainsString('?', $sql, '应保留占位符形式的 SQL');
      }
    });
  }

  // ---------------------------------------------------------------- B1-11

  /**
   * 克隆查询应隔离 orderBy 中的 Raw 片段，克隆后修改原始 Raw 不影响克隆体
   */
  public function testCloneIsolatesRawFragments(): void
  {
    $raw = DbFacade::raw('RAND()');
    $channel = new FakeChannel($this->makeEmptySelectStatement());
    $query = $channel->table('users')->orderBy($raw);
    $clone = clone $query;

    // 模拟克隆后原始 Raw 被原地改写（如 chunk 场景对 $raw->sql 的正则替换）
    $raw->sql = 'NOW()';

    $optionsProp = new ReflectionProperty(BaseQuery::class, 'options');
    $cloneOrderBy = $optionsProp->getValue($clone)->orderBy;
    self::assertSame('RAND()', $cloneOrderBy[0]->sql, '克隆体不应被原始 Raw 的改写污染');
  }
}
