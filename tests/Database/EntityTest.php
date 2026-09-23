<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Core\App;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\DbManager;
use Viswoole\Database\Entity;
use Viswoole\Database\Entity\EntityCollection;
use Viswoole\Database\Exception\DataNotFoundException;
use Viswoole\Database\Model;

/**
 * Entity 实体模型测试
 *
 * 验证类型化属性水合（严格类型强转）、驼峰↔蛇形映射、附加数据（关联/pivot）
 * 承载、toArray 序列化（枚举/日期/隐藏字段/获取器）、save 增量更新与
 * 自增回填、delete、find/select/cursor/create 出口的实体化返回，
 * 以及 EntityCollection 的集合操作与批量更新/删除。
 */
class EntityTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建带 FakeChannel 默认通道的应用环境
   *
   * @param FakeChannel $fake 通道测试替身
   * @return DbManager 注入替身通道的管理器
   */
  private function makeManager(FakeChannel $fake): DbManager
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $fake);
    // 关闭 SQL 调试控制台输出，避免污染 PHPUnit 输出被标记为 risky
    $manager->setDebug(false);
    return $manager;
  }

  /**
   * 创建包含实体全字段（蛇形列）两行数据的 SELECT 语句
   *
   * @param bool $withRows 是否插入数据，false 用于空结果用例
   * @return PDOStatement 查询语句
   */
  private function makeEntitySelectStatement(bool $withRows = true): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(
      'CREATE TABLE users (id INTEGER, user_name TEXT, age INTEGER, status INTEGER, remark TEXT, create_time TEXT)'
    );
    if ($withRows) {
      $pdo->exec("INSERT INTO users VALUES (1, 'alice', 20, 1, NULL, '2026-01-01 00:00:00')");
      $pdo->exec("INSERT INTO users VALUES (2, 'bob', 30, 2, 'keep', NULL)");
    }
    return $pdo->query('SELECT * FROM users');
  }

  /**
   * 水合严格类型强转：数字字符串→int、枚举、日期对象、可空 NULL
   */
  public function testHydrationCoercesTypes(): void
  {
    $entity = new UserEntity([
      'id' => '1',
      'user_name' => 'alice',
      'age' => '20',
      'status' => 1,
      'remark' => null,
      'create_time' => '2026-01-01 08:30:00',
    ]);

    self::assertSame(1, $entity->id, '数字字符串应强转为 int');
    self::assertSame('alice', $entity->userName, '蛇形列应映射到驼峰属性');
    self::assertSame(20, $entity->age);
    self::assertSame(UserStatus::Active, $entity->status, '标量应强转为回退枚举实例');
    self::assertNull($entity->remark, '可空属性应接受 NULL');
    self::assertInstanceOf(DateTimeImmutable::class, $entity->createTime);
    self::assertSame('2026-01-01 08:30:00', $entity->createTime->format('Y-m-d H:i:s'));
  }

  /**
   * 未映射到属性的列（关联数据等）应存入附加数据，可通过属性访问但不参与写入
   */
  public function testUnmappedColumnStoredAsExtra(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    $entity = new UserEntity([
      'id' => 1,
      'user_name' => 'alice',
      'age' => 20,
      'status' => 1,
      'articles' => ['title' => 'hello'],
    ]);

    self::assertSame(['title' => 'hello'], $entity->articles, '附加数据应通过属性访问');
    self::assertSame(['title' => 'hello'], $entity->getValue('articles'), 'getValue 应能读取附加数据');
    // 附加数据不参与脱水：toArray 输出但 save 不写入
    self::assertArrayHasKey('articles', $entity->toArray());
    $entity->save();
    self::assertNotContains('hello', $fake->calls[0]['bindings'], '附加数据不应写入数据库');
  }

  /**
   * 非可空属性遇到 NULL 应抛出清晰异常
   */
  public function testNonNullablePropertyRejectsNull(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('不允许为空');
    new UserEntity(['id' => 1, 'user_name' => 'alice', 'age' => 20, 'status' => null]);
  }

  /**
   * 值超出枚举定义范围应抛出异常并提示合法值
   */
  public function testInvalidEnumValueThrows(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('UserStatus');
    new UserEntity(['id' => 1, 'user_name' => 'alice', 'age' => 20, 'status' => 99]);
  }

  /**
   * readonly 属性应在构建元数据时抛出清晰异常（水合与 save 均需赋值）
   */
  public function testReadonlyPropertyThrows(): void
  {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('readonly');
    new ReadonlyFieldEntity(['user_name' => 'alice']);
  }

  /**
   * 无任何属性赋值的空白实体 save() 应被拦截（防止 MySQL 下
   * INSERT INTO t () VALUES () 静默插入全默认值记录）
   */
  public function testSaveBlankEntityThrows(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('无任何属性值');
    (new UserEntity())->save();
  }

  /**
   * toArray 应输出蛇形列键：枚举转回退值、日期格式化、隐藏字段过滤、获取器生效
   */
  public function testToArraySerializes(): void
  {
    $entity = new UserEntity([
      'id' => 1,
      'user_name' => 'alice',
      'age' => 20,
      'status' => 2,
      'remark' => 'secret',
      'create_time' => '2026-01-01 08:30:00',
    ]);

    $array = $entity->toArray();
    self::assertSame(1, $array['id']);
    self::assertSame('alice', $array['user_name']);
    self::assertSame(2, $array['status'], '枚举应脱水为回退值');
    self::assertSame('2026-01-01 08:30:00', $array['create_time'], '日期对象应格式化为字符串');
    self::assertSame(120, $array['age'], 'toArray 应应用 getAgeAttr 获取器');
    self::assertArrayNotHasKey('remark', $array, '隐藏字段不应输出');
  }

  /**
   * 新实体 save() 应走插入并回填自增主键，且再次 save 为无变更
   */
  public function testSaveOnNewEntityInsertsAndBackfillsPk(): void
  {
    // insertGetId 路径 execute 返回字符串自增 ID
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    $entity = new UserEntity(['user_name' => 'alice', 'age' => 20, 'status' => UserStatus::Active]);
    self::assertTrue($entity->save(), '新实体保存应返回 true');
    self::assertSame(7, $entity->id, '自增主键应回填并强转为属性类型');
    // 主键未初始化不参与写入；声明默认值的可空属性显式写入 NULL
    self::assertSame(['alice', 20, 1, null, null], $fake->calls[0]['bindings'], '写入绑定应为脱水后的属性值');

    // 已落库：无变更时再次 save 不应产生 SQL
    $calls = count($fake->calls);
    self::assertFalse($entity->save(), '无变更时 save 应返回 false');
    self::assertSame($calls, count($fake->calls), '无变更时不应执行 SQL');
  }

  /**
   * 已持久化实体 save() 应仅 UPDATE 变更列，并用原始主键定位
   */
  public function testSaveOnHydratedEntityUpdatesDirtyColumnsOnly(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    // 模拟数据库行水合（fromRow 记录快照并标记已持久化）
    $entity = UserEntity::fromRow([
      'id' => 1,
      'user_name' => 'alice',
      'age' => 20,
      'status' => 1,
      'remark' => null,
      'create_time' => '2026-01-01 00:00:00',
    ]);
    $entity->age = 21;
    self::assertTrue($entity->save());
    // 仅变更列进入 UPDATE，主键列不重复写入
    self::assertSame([21], $fake->calls[0]['bindings'], 'UPDATE 应只包含变更列');

    // save 成功后变更基准重置为最新库内状态：改回 20 相对 21 是真实变更
    $entity->age = 20;
    self::assertTrue($entity->save(), '基于最新库内状态改回 20 属于真实变更');
    self::assertSame([20], $fake->calls[1]['bindings'], '第二次 UPDATE 仅包含变更列');
  }

  /**
   * delete() 应按主键删除记录
   */
  public function testDeleteUsesPrimaryKey(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    $entity = new UserEntity(['id' => 1, 'user_name' => 'alice', 'age' => 20, 'status' => 1]);
    self::assertSame(1, $entity->delete());
    self::assertCount(1, $fake->calls, '应执行一条删除 SQL');
  }

  /**
   * 未持久化（无主键）实体删除应抛出清晰异常
   */
  public function testDeleteWithoutPkThrows(): void
  {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('缺少主键');
    $fake = new FakeChannel(1);
    $this->makeManager($fake);
    (new UserEntity(['user_name' => 'alice', 'age' => 20, 'status' => 1]))->delete();
  }

  /**
   * find 应返回水合后的实体实例，属性带精确类型
   */
  public function testFindReturnsHydratedEntity(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement());
    $this->makeManager($fake);

    $entity = UserEntity::find(1);
    self::assertInstanceOf(UserEntity::class, $entity);
    self::assertSame(1, $entity->id);
    self::assertSame('alice', $entity->userName);
    self::assertSame(UserStatus::Active, $entity->status);
    self::assertInstanceOf(DateTimeImmutable::class, $entity->createTime);
  }

  /**
   * select 应返回 EntityCollection，元素为实体实例
   */
  public function testSelectReturnsEntityCollection(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement());
    $this->makeManager($fake);

    $collection = UserEntity::select();
    self::assertInstanceOf(EntityCollection::class, $collection);
    self::assertCount(2, $collection);
    self::assertInstanceOf(UserEntity::class, $collection[0]);
    self::assertSame('bob', $collection->last()?->userName);
    // 集合可递归序列化为实体数组
    $array = $collection->toArray();
    self::assertSame('alice', $array[0]['user_name']);
    self::assertInstanceOf(BaseCollection::class, $collection);
  }

  /**
   * cursor 应逐条产出实体
   */
  public function testCursorYieldsEntities(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement());
    $this->makeManager($fake);

    $names = [];
    foreach (UserEntity::cursor() as $entity) {
      self::assertInstanceOf(UserEntity::class, $entity);
      $names[] = $entity->userName;
    }
    self::assertSame(['alice', 'bob'], $names);
  }

  /**
   * create 应返回含主键的实体，且已记录快照（后续 save 为增量更新语义）
   */
  public function testCreateReturnsEntityWithPk(): void
  {
    $fake = new FakeChannel('9');
    $this->makeManager($fake);

    $entity = UserEntity::create(['user_name' => 'carol', 'age' => 18, 'status' => 1]);
    self::assertInstanceOf(UserEntity::class, $entity);
    self::assertSame(9, $entity->id);
    self::assertSame('carol', $entity->userName);
    // 已落库：无变更时 save 返回 false
    self::assertFalse($entity->save());
  }

  /**
   * 查询为空且允许空时 find 应返回 null（?TEntity 语义）；
   * 不允许空时抛出 DataNotFoundException
   */
  public function testFindEmptyReturnsNull(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement(false));
    $this->makeManager($fake);

    self::assertNull(UserEntity::find(1), '空结果且允许空时应返回 null');
    $this->expectException(DataNotFoundException::class);
    UserEntity::find(1, allowEmpty: false);
  }

  /**
   * 模型路径（DataSet）空结果同样返回 null：find/first 未命中不再返回空 DataSet
   */
  public function testModelFindEmptyReturnsNull(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement(false));
    $this->makeManager($fake);

    $plain = new PlainDataSetModel();
    self::assertNull($plain->query->find(1), '模型路径空结果应返回 null');
    self::assertNull($plain->query->first(), '模型路径 first 空结果应返回 null');
  }

  /**
   * 已水合实体修改主键后 save()：用原始主键定位行，新主键进入变更列表一并更新
   */
  public function testSaveWithModifiedPrimaryKey(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    $entity = UserEntity::fromRow([
      'id' => 1,
      'user_name' => 'alice',
      'age' => 20,
      'status' => 1,
      'remark' => null,
      'create_time' => '2026-01-01 00:00:00',
    ]);
    $entity->id = 2;
    self::assertTrue($entity->save());
    // 新主键进入 SET 列表一起更新（FakeChannel 仅平铺 data，WHERE 定位值不进绑定）
    self::assertSame(2, $entity->id, '主键属性应更新为新值');
    self::assertSame([2], $fake->calls[0]['bindings'], 'SET 列表应包含新主键');
  }

  /**
   * find 命中的实体无变更时 save() 返回 false（快照对比生效）
   */
  public function testSaveHydratedEntityWithoutChangesReturnsFalse(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement());
    $this->makeManager($fake);

    $entity = UserEntity::find(1);
    self::assertInstanceOf(UserEntity::class, $entity);
    $calls = count($fake->calls);
    self::assertFalse($entity->save(), '无变更时 save 应返回 false');
    self::assertSame($calls, count($fake->calls), '无变更时不应执行 SQL');
  }

  /**
   * 实体 with() 关联查询：关联行经关联模型的水合出口返回实体，
   * 未命中外键的行填充空白载体（一对多空集合/一对一空白实体）
   */
  public function testEntityRelationHydration(): void
  {
    // 主表 1 行（id=1），关联表 2 行（user_id=1 的文章）
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, user_name TEXT, age INTEGER, status INTEGER, remark TEXT, create_time TEXT)');
    $pdo->exec('CREATE TABLE articles (id INTEGER, user_id INTEGER, title TEXT)');
    $pdo->exec("INSERT INTO users VALUES (1, 'alice', 20, 1, NULL, NULL)");
    $pdo->exec("INSERT INTO articles VALUES (10, 1, 'first'), (11, 1, 'second')");
    $mainStatement = $pdo->query('SELECT * FROM users');
    $relationStatement = $pdo->query('SELECT * FROM articles');

    // FakeChannel 无法按 SQL 区分返回值：直接经水合出口验证 RelationQuery 链路
    $fake = new FakeChannel($relationStatement);
    $this->makeManager($fake);

    $author = new UserEntity();
    $relation = $author->articlesRelation();
    // 主数据 1 行，外键 user_id=1 命中关联表两行（关联模型为 ArticleEntity）
    $map = $relation->query([['id' => 1, 'user_id' => 1]]);
    $collection = $map[1] ?? null;
    self::assertInstanceOf(EntityCollection::class, $collection, '一对多关联应返回实体集合');
    self::assertCount(2, $collection);
    self::assertInstanceOf(ArticleEntity::class, $collection[0], '关联行应以关联模型的实体承载');
    self::assertSame(1, $collection[0]->userId);
    self::assertSame('first', $collection[0]->getValue('title'), '未声明为属性的关联列应进附加数据');

    // 未命中外键：mergeRelationData 走空载体路径
    $empty = $relation->query([['id' => 2, 'user_id' => 2]]);
    self::assertSame([], $empty, '无命中键时返回空映射，由 mergeRelationData 填充空载体');
    $mainFake = new FakeChannel($mainStatement);
    $this->makeManager($mainFake);
    $row = UserEntity::fromRow(['id' => 3, 'user_name' => 'bob', 'age' => 30, 'status' => 1]);
    $merged = $this->mergeWithRelations($row);
    self::assertInstanceOf(EntityCollection::class, $merged['articles'], '未命中外键的一对多应填充空实体集合');
    self::assertCount(0, $merged['articles']);
  }

  /**
   * 模拟 Query::mergeRelationData 的未命中填充路径
   *
   * @param UserEntity $row 主表实体
   * @return array<string,mixed> 填充关联后的行数据
   */
  private function mergeWithRelations(UserEntity $row): array
  {
    $relationQuery = $row->articlesRelation()->relationModel()->query;
    return [
      'id' => $row->id,
      'articles' => $relationQuery->newRowsCollection(),
    ];
  }

  /**
   * 实体集合：行数组自动水合、first/filter/each、非实体元素应被拒绝
   */
  public function testEntityCollectionOperations(): void
  {
    $fake = new FakeChannel($this->makeEntitySelectStatement());
    $this->makeManager($fake);

    $collection = new EntityCollection(
      (new UserEntity())->query()->where('id', '>', 0),
      [
        ['id' => 1, 'user_name' => 'alice', 'age' => 20, 'status' => 1],
        ['id' => 2, 'user_name' => 'bob', 'age' => 30, 'status' => 2],
      ]
    );
    self::assertCount(2, $collection);
    self::assertInstanceOf(UserEntity::class, $collection->first());

    $filtered = $collection->filter(fn(UserEntity $e) => $e->age > 25);
    self::assertSame('bob', $filtered->first()?->userName, 'filter 应保留满足条件的实体');

    $names = [];
    $collection->each(function (UserEntity $e) use (&$names) {
      $names[] = $e->userName;
    });
    self::assertSame(['alice', 'bob'], $names);

    // 非实体元素应被拒绝
    $this->expectException(InvalidArgumentException::class);
    /** @phpstan-ignore-next-line 故意传入非法元素验证守卫 */
    $collection[] = ['id' => 3];
  }

  /**
   * 实体集合批量更新应同步内存实体并重置变更基准
   */
  public function testEntityCollectionUpdate(): void
  {
    $fake = new FakeChannel(2);
    $this->makeManager($fake);

    $collection = new EntityCollection(
      (new UserEntity())->query()->where('id', '>', 0),
      [
        ['id' => 1, 'user_name' => 'alice', 'age' => 20, 'status' => 1],
        ['id' => 2, 'user_name' => 'bob', 'age' => 30, 'status' => 2],
      ]
    );
    self::assertSame(2, $collection->update(['status' => 2]));
    $collection->each(function (UserEntity $e) {
      self::assertSame(UserStatus::Disabled, $e->status, '批量更新应同步内存实体');
    });
  }

  /**
   * 实体集合批量删除应按主键 IN 匹配
   */
  public function testEntityCollectionDelete(): void
  {
    $fake = new FakeChannel(2);
    $this->makeManager($fake);

    $collection = new EntityCollection(
      (new UserEntity())->query()->where('id', '>', 0),
      [
        ['id' => 1, 'user_name' => 'alice', 'age' => 20, 'status' => 1],
        ['id' => 2, 'user_name' => 'bob', 'age' => 30, 'status' => 2],
      ]
    );
    self::assertSame(2, $collection->delete());
    self::assertCount(1, $fake->calls, '应合并为一条批量删除 SQL');
  }
}

/**
 * 用户状态枚举（测试夹具）
 */
enum UserStatus: int
{
  case Active = 1;
  case Disabled = 2;
}

/**
 * 测试用实体：全字段类型化声明，remark 为隐藏字段，age 定义获取器
 */
class UserEntity extends Entity
{
  protected string $table = 'users';
  /** @var array<int,string> 隐藏字段 */
  protected array $hidden = ['remark'];

  public int $id;
  public string $userName;
  public int $age;
  public UserStatus $status;
  public ?string $remark = null;
  public ?DateTimeImmutable $createTime = null;

  /**
   * 获取器：年龄 +100（验证 toArray 应应用获取器）
   *
   * @param mixed $value 原始值
   * @return int 转换后的值
   */
  public function getAgeAttr(mixed $value): int
  {
    return (int)$value + 100;
  }

  /**
   * 一对多关联：用户 → 文章（ArticleEntity），供关联水合测试使用
   *
   * @return \Viswoole\Database\Model\RelationQuery 关联查询实例
   */
  public function articlesRelation(): \Viswoole\Database\Model\RelationQuery
  {
    return $this->hasMany(ArticleEntity::class, 'user_id', 'id');
  }
}

/**
 * 测试用实体：文章（关联水合测试夹具），title 为未声明属性走附加数据
 */
class ArticleEntity extends Entity
{
  protected string $table = 'articles';
  protected string $pk = 'id';

  public int $id;
  public int $userId;
}

/**
 * 测试用实体：声明 readonly 属性，水合构建元数据时应抛出清晰异常
 */
class ReadonlyFieldEntity extends Entity
{
  protected string $table = 'users';

  public readonly string $userName;
}

/**
 * 测试用普通模型（非实体）：验证基类 find/first 空结果返回 null
 */
class PlainDataSetModel extends Model
{
  protected string $table = 'users';
}
