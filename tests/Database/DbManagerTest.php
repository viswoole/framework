<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
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
}
