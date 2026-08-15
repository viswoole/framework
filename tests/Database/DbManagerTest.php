<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Core\App;
use Viswoole\Database\DbManager;

/**
 * 数据库管理器原生查询（query/execute）测试
 *
 * 验证与 think-orm 一致的语义：query 返回结果集并透传 $master，
 * execute 返回受影响行数而非 PDOStatement。
 */
class DbManagerTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建一个已将默认通道替换为测试替身的 DbManager
   *
   * 通过应用容器自动装配 Config 与 LogManager，再覆盖默认通道为测试替身。
   */
  private function makeManager(FakeChannel $fake): DbManager
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $fake);
    return $manager;
  }

  /**
   * execute 返回 PDOStatement 时，DbManager::execute 应返回受影响行数
   */
  public function testExecuteReturnsAffectedRowsFromStatement(): void
  {
    $manager = $this->makeManager(new FakeChannel($this->makeAffectedRowsStatement(3)));
    self::assertSame(3, $manager->execute('UPDATE users SET name = ?', ['x']));
  }

  /**
   * 通道 execute 直接返回整数时，DbManager::execute 应原样返回
   */
  public function testExecuteReturnsIntForNonPdoChannel(): void
  {
    $fake = new FakeChannel(2);
    $manager = $this->makeManager($fake);
    self::assertSame(2, $manager->execute('UPDATE users SET status = 1'));
    self::assertSame('execute', $fake->calls[0]['method']);
  }

  /**
   * 通道 execute 返回字符串（如自增ID）时，应原样返回而不强制转 int
   */
  public function testExecutePassesStringResultThrough(): void
  {
    $fake = new FakeChannel('5');
    $manager = $this->makeManager($fake);
    self::assertSame('5', $manager->execute('INSERT INTO users (name) VALUES (?)', ['a']));
  }

  /**
   * DbManager::execute 应透传 $getId 到通道 execute
   */
  public function testExecutePassesGetIdToChannel(): void
  {
    $fake = new FakeChannel('5');
    $manager = $this->makeManager($fake);
    self::assertSame('5', $manager->execute('INSERT INTO users (name) VALUES (?)', ['a'], 'id'));
    self::assertSame('id', $fake->calls[0]['getId']);
  }

  /**
   * DbManager::query 应透传 $master 到通道 execute，并返回通道结果
   */
  public function testQueryPassesMasterToChannel(): void
  {
    $fake = new FakeChannel($this->makeSelectStatement());
    $manager = $this->makeManager($fake);
    $result = $manager->query('SELECT * FROM users', ['id' => 1], true);
    self::assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], $result);
    self::assertSame('execute', $fake->calls[0]['method']);
    self::assertTrue($fake->calls[0]['master']);
    self::assertSame(['id' => 1], $fake->calls[0]['bindings']);
  }

  /**
   * 闭包事务应把闭包返回值透传给调用方（修复：原实现返回 void 丢失返回值）
   */
  public function testStartTransactionReturnsClosureReturnValue(): void
  {
    $manager = $this->makeManager(new FakeChannel(0));
    // 空事务（闭包内无 DB 操作）下 commit 无连接可提交，仅验证返回值透传
    $result = $manager->startTransaction(fn() => ['uid' => 12345]);
    self::assertSame(['uid' => 12345], $result);
    self::assertSame(42, $manager->startTransaction(fn(): int => 42));
  }

  /**
   * 闭包抛异常时应先回滚再原样重抛，且事务状态重置允许再次开启事务
   */
  public function testStartTransactionRethrowsClosureExceptionAfterRollback(): void
  {
    $manager = $this->makeManager(new FakeChannel(0));
    try {
      $manager->startTransaction(function () {
        throw new RuntimeException('boom');
      });
      self::fail('闭包异常应被重抛而非吞掉');
    } catch (RuntimeException $e) {
      self::assertSame('boom', $e->getMessage());
    }
    // 回滚后事务状态应已重置，可再次开启新事务
    self::assertSame('ok', $manager->startTransaction(fn(): string => 'ok'));
  }

  /**
   * 未传闭包时仅开启事务并返回 null，语义等同 start()
   */
  public function testStartTransactionWithoutClosureReturnsNull(): void
  {
    $manager = $this->makeManager(new FakeChannel(0));
    try {
      self::assertNull($manager->startTransaction());
    } finally {
      // 复位事务状态，避免污染后续用例
      $manager->commit();
    }
  }
}
