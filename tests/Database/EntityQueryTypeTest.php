<?php

declare(strict_types=1);

/* 职责：验证 mixed 文档回退不改变实体、SQL 预览及查询器扩展的运行时契约。 */

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Viswoole\Core\App;
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\DbManager;
use Viswoole\Database\Entity;
use Viswoole\Database\Entity\EntityQuery;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * 单条实体读取的类型与兼容性回归测试。
 */
final class EntityQueryTypeTest extends TestCase
{
  /**
   * 保留实体读取及空结果
   *
   * 与 IDE 样例使用相同的查询写法，确保文档变动不影响水合。
   *
   * @return void 命中和未命中均满足原有契约
   */
  public function testOwnedQueriesReturnEntityOrNull(): void
  {
    foreach (['findOwned', 'firstOwned', 'findDirect'] as $method) {
      $this->registerChannel(true);
      $result = $method === 'findDirect'
        ? TypeQueryEntity::$method(1)
        : TypeQueryEntity::$method(2, 1);
      self::assertInstanceOf(TypeQueryEntity::class, $result);
      self::assertSame(1, $result->id);
      $this->registerChannel(false);
      self::assertNull($method === 'findDirect' ? TypeQueryEntity::$method(1) : TypeQueryEntity::$method(2, 1));
    }
  }

  /**
   * 保留实例已有条件与执行选项
   *
   * 类型文档不能让实例入口变成创建全新查询器的静态包装。
   *
   * @return void 实例复用原查询器
   */
  public function testInstanceEntryPreservesQueryState(): void
  {
    $this->registerChannel(true);
    $entity = new TypeQueryEntity();
    $query = $entity->query()->where('club_id', 2)->master()->lockForUpdate();
    self::assertSame($query, $entity->where('id', 1));
    $options = (new ReflectionProperty(BaseQuery::class, 'options'))->getValue($query);
    self::assertInstanceOf(Options::class, $options);
    self::assertSame(['club_id', 'id'], array_column($options->where, 'column'));
    self::assertTrue($options->master);
    self::assertTrue($options->lockForUpdate);
  }

  /**
   * 保留原地 SQL 预览及自动重置语义
   *
   * 忽略 toRaw 返回值仍必须阻止紧随其后的写入；完成后按原约定恢复普通查询。
   *
   * @return void 预览不执行且不创建独立对象
   */
  public function testToRawKeepsOriginalMutableContract(): void
  {
    $channel = $this->registerChannel(true);
    $query = $channel->table('type_query')->where('id', 1);
    self::assertSame($query, $query->toRaw());
    self::assertInstanceOf(Raw::class, $query->update(['club_id' => 3]));
    self::assertSame([], $channel->calls);
    $query->where('id', 1)->update(['club_id' => 3]);
    self::assertCount(1, $channel->calls);
  }

  /**
   * 保留实体查询的 SQL 返回分支
   *
   * find 与 first 的原生签名必须继续允许 Raw，不能因 IDE 类型提示而抛出 TypeError。
   *
   * @return void 单条读取预览均返回 Raw
   */
  public function testEntityReadsStillSupportSqlPreview(): void
  {
    $channel = $this->registerChannel(true);
    foreach (['find', 'first'] as $method) {
      $query = TypeQueryEntity::where('id', 1);
      $query->toRaw();
      self::assertInstanceOf(Raw::class, $query->$method());
    }
    self::assertSame([], $channel->calls);
  }

  /**
   * 保留已有查询器扩展签名
   *
   * 原本合法的覆写必须继续可加载，不能被父类突然收窄返回类型破坏。
   *
   * @return void 原有签名可继续执行 SQL 预览
   */
  public function testExistingQueryOverrideRemainsCompatible(): void
  {
    $channel = $this->registerChannel(true);
    $query = new class (new TypeQueryEntity()) extends EntityQuery {
      /**
       * 复用原有查询器扩展签名
       *
       * 仅转发父类，验证既有继承契约不变。
       *
       * @param int|string|null $value 主键值
       * @param bool $allowEmpty 是否允许空结果
       * @return DataSet|Entity|Raw|null 原查询结果
       */
      public function find(int|string|null $value = null, bool $allowEmpty = true): DataSet|Entity|Raw|null
      {
        return parent::find($value, $allowEmpty);
      }
    };
    self::assertInstanceOf(Raw::class, $query->toRaw()->find(1));
    self::assertSame([], $channel->calls);
  }

  /**
   * 注册隔离查询通道
   *
   * SQLite 只用于内存结果集，所有 SQL 执行均由替身记录，不接触外部存储。
   *
   * @param bool $hasRows 是否提供命中行
   * @return FakeChannel 可观察执行次数的通道
   */
  private function registerChannel(bool $hasRows): FakeChannel
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('需要 pdo_sqlite 验证实体读取');
    }
    $database = new PDO('sqlite::memory:');
    $database->exec('CREATE TABLE type_query (id INTEGER, club_id INTEGER)');
    if ($hasRows) {
      $database->exec('INSERT INTO type_query VALUES (1, 2)');
    }
    $channel = new FakeChannel($database->query('SELECT * FROM type_query'));
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $channel);
    $manager->setDebug(false);
    return $channel;
  }
}
