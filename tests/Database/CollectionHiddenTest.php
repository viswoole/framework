<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;

/**
 * 集合隐藏字段（hidden）行为测试
 *
 * 修复回归——BaseCollection::toArray() 此前仅对数组值递归 removeHiddenKeys，
 * 顶层标量字段（如 password 哈希）从未被 $hidden 过滤；且 toArray() 的
 * $hidden 形参未参与判断，传 false 仍会过滤。本测试锁定修复后的行为。
 */
class CollectionHiddenTest extends TestCase
{
  /**
   * 构建定义了 $hidden 的 users 模型，并替换默认通道为 Sqlite 替身
   *
   * Sqlite 返回一行：['id' => 1, 'name' => 'a', 'password' => 'hash']
   */
  private function makeModel(): Model
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', new FakeChannel($this->makeSelectStatementWithPassword()));

    return new class extends Model {
      protected string $table = 'users';

      /** 对外输出时隐藏密码哈希 */
      protected array $hidden = ['password'];
    };
  }

  /**
   * 创建包含 password 列的 users 表并返回 SELECT 语句
   *
   * @return PDOStatement 含一条记录的结果集语句
   */
  private function makeSelectStatementWithPassword(): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT, password TEXT)');
    $pdo->exec("INSERT INTO users VALUES (1, 'a', 'hash')");
    return $pdo->query('SELECT * FROM users');
  }

  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志（避免 PHPUnit 标记 risky）
   *
   * @template T
   * @param callable(): T $fn 无参回调，内部执行触发查询的链式调用
   * @return T 回调返回值
   */
  private function runQuietly(callable $fn): mixed
  {
    ob_start();
    try {
      return $fn();
    } finally {
      ob_end_clean();
    }
  }

  /**
   * 单行 DataSet：模型 $hidden 的顶层标量字段应被过滤（修复前 password 泄漏）
   */
  public function testHiddenScalarFieldFilteredOnDataSet(): void
  {
    $model = $this->makeModel();
    $row = $this->runQuietly(fn() => $model->query()->where('id', 1)->find());
    $array = $row->toArray();
    self::assertArrayNotHasKey('password', $array);
    self::assertSame(['id' => 1, 'name' => 'a'], $array);
  }

  /**
   * 多行 Collection：每行子 DataSet 的 hidden 同样生效
   */
  public function testHiddenScalarFieldFilteredOnCollection(): void
  {
    $model = $this->makeModel();
    $rows = $this->runQuietly(fn() => $model->query()->select());
    foreach ($rows->toArray() as $row) {
      self::assertArrayNotHasKey('password', $row);
    }
  }

  /**
   * toArray() 第二参传 false 应跳过隐藏过滤（修复前形参未生效）
   */
  public function testToArrayHiddenFalseKeepsAllFields(): void
  {
    $model = $this->makeModel();
    $row = $this->runQuietly(fn() => $model->query()->where('id', 1)->find());
    $array = $row->toArray(true, false);
    self::assertSame('hash', $array['password'] ?? null);
  }

  /**
   * 未列入 $hidden 的字段不受影响
   */
  public function testNonHiddenFieldsUnaffected(): void
  {
    $model = $this->makeModel();
    $row = $this->runQuietly(fn() => $model->query()->where('id', 1)->find());
    $array = $row->toArray();
    self::assertSame(1, $array['id']);
    self::assertSame('a', $array['name']);
  }
}
