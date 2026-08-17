<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use function Swoole\Coroutine\run;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Collection;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;
use Viswoole\Database\Model\BelongsToMany;
use Viswoole\Database\Model\Query;
use Viswoole\Database\Model\RelationQuery;

/**
 * 多对多关联（belongsToMany）单元测试
 *
 * 基于真实 SQLite 文件库执行实际 SQL，验证：
 * 中间表两步查询与填充、pivot 数据附加、表名/键名自动推断、
 * handle() 对关联查询的过滤、重复绑定去重、悬空绑定跳过，
 * 以及协程并发环境下与 with() 的协同。
 */
class BelongsToManyTest extends TestCase
{
  /** @var string 三个模型共用的通道名（同一 SQLite 文件） */
  private const CHANNEL = 'btm_db';
  /** @var string|null 当前用例的临时库文件路径 */
  private static ?string $tmpDb = null;

  protected function setUp(): void
  {
    // 每个用例独立建库，避免用例间数据串扰
    self::$tmpDb = $this->makeSqliteFile();
    $db = App::factory()->make(DbManager::class);
    // 关闭调试输出：Output::echo 会关闭输出缓冲，导致 PHPUnit 报 risky
    $db->setDebug(false);
    $db->addChannel(self::CHANNEL, $this->makeSqliteChannel(self::$tmpDb));
  }

  protected function tearDown(): void
  {
    // 清理临时库文件，避免测试机器残留
    if (self::$tmpDb !== null && is_file(self::$tmpDb)) unlink(self::$tmpDb);
    self::$tmpDb = null;
  }

  /**
   * 基本多对多填充：按中间表映射组装各主行的关联集合
   */
  public function testBasicManyToManyFill(): void
  {
    $rows = $this->newUserQuery()->with(['roles'])->getArray();
    self::assertCount(3, $rows, '主表三行数据应全部返回');

    // u1 绑定 admin、editor 两个角色，且附带各自的 pivot 行
    $u1Roles = $rows[0]['roles'];
    self::assertInstanceOf(Collection::class, $u1Roles);
    self::assertCount(2, $u1Roles, 'u1 应关联两个角色');
    $admin = $u1Roles->where('name', 'admin')->first();
    self::assertSame(10, $admin['id']);
    self::assertSame(
      ['user_id' => 1, 'role_id' => 10, 'bind_time' => '2026-01-01'],
      $admin['pivot'],
      '关联数据应附带对应中间表行'
    );

    // u2 与 u1 共享 admin 角色，各自拿到独立实例与各自的 pivot
    $u2Admin = $rows[1]['roles']->where('name', 'admin')->first();
    self::assertSame(['user_id' => 2, 'role_id' => 10, 'bind_time' => '2026-01-05'], $u2Admin['pivot']);

    // u3 的绑定指向已删除的角色 12，应得到空集合而非报错
    self::assertCount(0, $rows[2]['roles'], '悬空绑定应回填空集合');
  }

  /**
   * with() 闭包（handle）条件作用于关联模型查询，可过滤关联数据
   */
  public function testHandleFiltersRelatedQuery(): void
  {
    $rows = $this->newUserQuery()->with([
      'roles' => fn(Query $query) => $query->where('name', '=', 'editor'),
    ])->getArray();

    self::assertCount(1, $rows[0]['roles'], 'u1 应只保留被 handle 过滤后的 editor');
    self::assertSame('editor', $rows[0]['roles']->first()['name']);
    self::assertCount(0, $rows[1]['roles'], 'u2 未绑定 editor 应为空集合');
  }

  /**
   * 重复绑定（同一主行与同一关联行绑定多次）应去重，保留最后一条 pivot
   */
  public function testDuplicateBindingDeduplicated(): void
  {
    $rows = $this->newUserQuery()->with(['roles'])->getArray();
    // u2 的 (2,10) 在中间表出现两次，集合中只应有一条 admin
    $u2Roles = $rows[1]['roles'];
    self::assertCount(1, $u2Roles, '重复绑定应去重');
    self::assertSame('2026-01-05', $u2Roles->first()['pivot']['bind_time'], '去重应保留最后一条中间表记录');
  }

  /**
   * 中间表传模型实例与传表名两种声明方式结果一致
   */
  public function testPivotModelInstanceForm(): void
  {
    $rows = $this->newUserQuery()->with(['rolesByModel'])->getArray();
    self::assertCount(2, $rows[0]['rolesByModel']);
    self::assertSame(10, $rows[0]['rolesByModel']->where('name', 'admin')->first()['id']);
    self::assertCount(1, $rows[1]['rolesByModel']);
  }

  /**
   * 全默认推断：中间表名 {当前表}_{关联表}，外键沿用 {表名}_{主键} 规则
   */
  public function testDefaultInference(): void
  {
    $userModel = new BtmUserModel();
    $relation = $userModel->defaultRoles();
    self::assertInstanceOf(BelongsToMany::class, $relation);
    self::assertInstanceOf(RelationQuery::class, $relation, '多对多应兼容 with() 的关联类型校验');
    self::assertSame('users_roles', $relation->pivotModel()->getTableName(), '中间表名应推断为 users_roles');
    self::assertSame('users_id', $relation->foreignPivotKey());
    self::assertSame('roles_id', $relation->relatedPivotKey());
    self::assertSame('id', $relation->localKey());
    self::assertSame('id', $relation->relatedKey());

    $rows = $userModel->query->with(['defaultRoles'])->getArray();
    self::assertCount(1, $rows[0]['defaultRoles']);
    self::assertSame(10, $rows[0]['defaultRoles']->first()['id']);
    self::assertSame(
      ['users_id' => 1, 'roles_id' => 10, 'bind_time' => 'd1'],
      $rows[0]['defaultRoles']->first()['pivot']
    );
  }

  /**
   * wherePivot 按绑定条件过滤中间表：不满足条件的绑定被剔除，未命中主行回填空集合
   */
  public function testWherePivotFiltersBindings(): void
  {
    // recentRoles：bind_time >= 2026-01-05
    $rows = $this->newUserQuery()->with(['recentRoles'])->getArray();
    // u1 两条绑定均早于 2026-01-05，应得到空集合
    self::assertCount(0, $rows[0]['recentRoles'], 'u1 无满足条件的绑定应为空集合');
    // u2 重复绑定去重后保留 2026-01-05 那条，仍关联 admin
    $u2Roles = $rows[1]['recentRoles'];
    self::assertCount(1, $u2Roles);
    self::assertSame('admin', $u2Roles->first()['name']);
    self::assertSame('2026-01-05', $u2Roles->first()['pivot']['bind_time'], '过滤后 pivot 仍应对应存留的绑定行');
    // u3 的绑定同样不满足条件
    self::assertCount(0, $rows[2]['recentRoles']);
  }

  /**
   * wherePivot 两参简写：数组值自动转 IN 条件
   */
  public function testWherePivotSimplifiedInForm(): void
  {
    // editorRoles：role_id IN (11)
    $rows = $this->newUserQuery()->with(['editorRoles'])->getArray();
    self::assertCount(1, $rows[0]['editorRoles'], 'u1 仅保留 editor 绑定');
    self::assertSame('editor', $rows[0]['editorRoles']->first()['name']);
    self::assertCount(0, $rows[1]['editorRoles'], 'u2 无 editor 绑定应为空集合');
  }

  /**
   * wherePivot 无效运算符在查询构建阶段被 where() 白名单校验拦截
   */
  public function testWherePivotRejectsInvalidOperator(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('运算符');
    (new BtmUserModel())->query->with(['badRoles'])->getArray();
  }

  /**
   * 协程环境：多对多与 with() 并发查询机制协同，多关联数据均完整填充
   */
  public function testConcurrentFillInCoroutine(): void
  {
    /** @var array|null 协程内填充的查询结果 */
    $rows = null;
    run(function () use (&$rows): void {
      $rows = $this->newUserQuery()->with(['roles', 'defaultRoles'])->getArray();
    });
    self::assertCount(3, $rows);
    self::assertCount(2, $rows[0]['roles'], '协程内 roles 关联应完整填充');
    self::assertCount(1, $rows[0]['defaultRoles'], '协程内 defaultRoles 关联应完整填充');
  }

  /**
   * 创建用户模型查询实例（通道已在 setUp 注册）
   */
  private function newUserQuery(): Query
  {
    return (new BtmUserModel())->query;
  }

  /**
   * 创建临时 SQLite 文件库并初始化多对多测试数据
   *
   * 数据约定：
   * - u1 绑定 admin(10)、editor(11)；u2 绑定 admin 两次（验证去重）；
   * - u3 绑定已删除的角色 12（验证悬空绑定跳过）；
   * - users_roles 表服务于默认推断场景，外键为 users_id/roles_id。
   *
   * @return string 数据库文件路径
   */
  private function makeSqliteFile(): string
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_btm_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER, bind_time TEXT)');
    $pdo->exec('CREATE TABLE users_roles (users_id INTEGER, roles_id INTEGER, bind_time TEXT)');
    $this->insertRows($pdo, 'users', ['id', 'name'], [
      [1, 'u1'], [2, 'u2'], [3, 'u3'],
    ]);
    $this->insertRows($pdo, 'roles', ['id', 'name'], [
      [10, 'admin'], [11, 'editor'], [99, 'ghost'],
    ]);
    $this->insertRows($pdo, 'role_user', ['user_id', 'role_id', 'bind_time'], [
      [1, 10, '2026-01-01'], [1, 11, '2026-01-02'],
      [2, 10, '2026-01-03'], [2, 10, '2026-01-05'],
      [3, 12, '2026-01-04'],
    ]);
    $this->insertRows($pdo, 'users_roles', ['users_id', 'roles_id', 'bind_time'], [
      [1, 10, 'd1'], [2, 11, 'd2'],
    ]);
    return $tmpDb;
  }

  /**
   * 基于临时 SQLite 文件创建数据库通道
   *
   * @param string $tmpDb 数据库文件路径
   * @return PDOChannel 已就绪的通道
   */
  private function makeSqliteChannel(string $tmpDb): PDOChannel
  {
    return new PDOChannel(type: DriverType::SQLite, database: $tmpDb);
  }

  /**
   * 向指定表批量插入行
   *
   * @param PDO $pdo 数据库连接
   * @param string $table 表名
   * @param array $columns 列名列表
   * @param array $rows 行数据（与列名顺序一致）
   */
  private function insertRows(PDO $pdo, string $table, array $columns, array $rows): void
  {
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $statement = $pdo->prepare("INSERT INTO $table (" . implode(',', $columns) . ") VALUES ($placeholders)");
    foreach ($rows as $row) $statement->execute($row);
  }
}

/**
 * 多对多测试主模型：users 表，演示三种 belongsToMany 声明方式
 */
class BtmUserModel extends Model
{
  protected string $table = 'users';
  protected ?string $channelName = 'btm_db';

  /**
   * 中间表直接传表名 + 显式外键
   */
  public function roles(): BelongsToMany
  {
    return $this->belongsToMany(BtmRoleModel::class, 'role_user', 'user_id', 'role_id');
  }

  /**
   * 中间表传模型实例 + 显式外键
   */
  public function rolesByModel(): BelongsToMany
  {
    return $this->belongsToMany(BtmRoleModel::class, BtmPivotModel::class, 'user_id', 'role_id');
  }

  /**
   * 全默认推断（users_roles 表，users_id / roles_id 外键）
   */
  public function defaultRoles(): BelongsToMany
  {
    return $this->belongsToMany(BtmRoleModel::class);
  }

  /**
   * wherePivot 运算符形式：仅取 2026-01-05 之后建立的绑定
   */
  public function recentRoles(): BelongsToMany
  {
    return $this->belongsToMany(BtmRoleModel::class, 'role_user', 'user_id', 'role_id')
      ->wherePivot('bind_time', '>=', '2026-01-05');
  }

  /**
   * wherePivot 两参简写形式：role_id IN (11)，仅取 editor 绑定
   */
  public function editorRoles(): BelongsToMany
  {
    return $this->belongsToMany(BtmRoleModel::class, 'role_user', 'user_id', 'role_id')
      ->wherePivot('role_id', [11]);
  }

  /**
   * 非法运算符示例：验证 wherePivot 延迟到查询构建阶段才做白名单校验
   */
  public function badRoles(): BelongsToMany
  {
    return $this->belongsToMany(BtmRoleModel::class, 'role_user', 'user_id', 'role_id')
      ->wherePivot('bind_time', '= 1 OR 1=1 --', 'x');
  }
}

/**
 * 多对多测试关联模型：roles 表
 */
class BtmRoleModel extends Model
{
  protected string $table = 'roles';
  protected ?string $channelName = 'btm_db';
}

/**
 * 多对多测试中间表模型：role_user 表
 */
class BtmPivotModel extends Model
{
  protected string $table = 'role_user';
  protected ?string $channelName = 'btm_db';
}
