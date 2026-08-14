<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Raw;

/**
 * 数据库通道基类原生查询（query）测试
 *
 * 通过测试替身验证 Channel::query 的三种行为：
 * PDOStatement 拉取结果集、写语句执行前拒绝、无结果集抛异常；
 * 以及 $master 强制主库参数的正确透传。
 */
class ChannelTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * query 应将 SQL 与绑定参数原样透传给 execute
   */
  public function testQueryPassesSqlAndBindingsToExecute(): void
  {
    $channel = new FakeChannel($this->makeSelectStatement());
    $channel->query('SELECT * FROM users WHERE id = ?', [1]);
    self::assertSame('SELECT * FROM users WHERE id = ?', $channel->calls[0]['sql']);
    self::assertSame([1], $channel->calls[0]['bindings']);
  }

  /**
   * 传入 Raw 对象时，query 应将对象原样透传给 execute
   */
  public function testQueryAcceptsRawObject(): void
  {
    $channel = new FakeChannel($this->makeSelectStatement());
    $raw = new Raw('SELECT * FROM users WHERE id = ?', [1]);
    $channel->query($raw);
    self::assertSame($raw, $channel->calls[0]['sql']);
    self::assertSame([], $channel->calls[0]['bindings']);
  }

  /**
   * 默认不强制主库，$master 应透传 false 给 execute
   */
  public function testQueryDefaultsMasterToFalse(): void
  {
    $channel = new FakeChannel($this->makeSelectStatement());
    $channel->query('SELECT * FROM users');
    self::assertFalse($channel->calls[0]['master']);
  }

  /**
   * $master=true 时应透传给 execute，且 getId 保持 false
   */
  public function testQueryPassesMasterFlagToExecute(): void
  {
    $channel = new FakeChannel($this->makeSelectStatement());
    $channel->query('SELECT * FROM users', [], true);
    self::assertTrue($channel->calls[0]['master']);
    self::assertFalse($channel->calls[0]['getId']);
  }

  /**
   * execute 返回 PDOStatement 时，query 应拉取全部结果并释放游标
   */
  public function testQueryFetchesRowsFromPdoStatement(): void
  {
    $channel = new FakeChannel($this->makeSelectStatement());
    self::assertSame(
      [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
      $channel->query('SELECT * FROM users')
    );
  }

  /**
   * 写入语句应在执行前被拒绝，不会触发 execute
   */
  public function testQueryRejectsWriteStatementBeforeExecution(): void
  {
    $channel = new FakeChannel(0);
    self::assertCount(0, $channel->calls, '执行前不应有任何调用记录');
    $this->expectException(DbException::class);
    $this->expectExceptionMessage('请改用 execute()');
    $channel->query('UPDATE users SET name = ?', ['x']);
  }

  /**
   * 查询语句分类器应识别查询关键字，并拒绝写入关键字
   */
  public function testIsQueryStatementClassifiesSql(): void
  {
    self::assertTrue(Channel::isQueryStatement('SELECT * FROM users'));
    self::assertTrue(Channel::isQueryStatement('  SHOW TABLES'));
    self::assertTrue(Channel::isQueryStatement('EXPLAIN SELECT * FROM users'));
    self::assertTrue(Channel::isQueryStatement('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    self::assertFalse(Channel::isQueryStatement('UPDATE users SET name = 1'));
    self::assertFalse(Channel::isQueryStatement('INSERT INTO users (name) VALUES (?)'));
    self::assertFalse(Channel::isQueryStatement('DELETE FROM users'));
  }

  /**
   * execute 未返回结果集（如返回 int）时，query 应抛出 DbException
   */
  public function testQueryThrowsWhenExecuteHasNoResultSet(): void
  {
    $channel = new FakeChannel(42);
    $this->expectException(DbException::class);
    $this->expectExceptionMessage('未返回结果集');
    $channel->query('SELECT * FROM users');
  }
}
