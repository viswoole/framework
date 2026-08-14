<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PDOStatement;

/**
 * 提供基于内存 SQLite 的 SELECT 语句
 *
 * 供 Database 相关单测验证 PDOStatement 的处理逻辑（fetchAll/rowCount/columnCount），
 * 当前环境未启用 pdo_sqlite 驱动时自动跳过用例。
 *
 * @method static void markTestSkipped(string $message = '') 标记用例跳过
 */
trait ProvidesSqliteStatement
{
  /**
   * 创建包含两行数据的 users 表，并返回其 SELECT 语句
   *
   * @return PDOStatement 含两条记录的结果集语句
   */
  protected function makeSelectStatement(): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
    $pdo->exec("INSERT INTO users VALUES (1, 'a'), (2, 'b')");
    return $pdo->query('SELECT * FROM users');
  }

  /**
   * 创建含指定行数的 users 表，并返回影响全部行的 UPDATE 语句
   *
   * @param int $count 插入的记录数，即 UPDATE 影响的行数
   * @return PDOStatement 无结果集的写语句
   */
  protected function makeAffectedRowsStatement(int $count = 3): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
    for ($i = 1; $i <= $count; $i++) {
      $pdo->exec("INSERT INTO users VALUES ($i, 'u$i')");
    }
    return $pdo->query("UPDATE users SET name = 'x'");
  }
}
