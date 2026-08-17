<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;
use Viswoole\Database\Model\BelongsToMany;
use Viswoole\Database\Model\RelationQuery;

/**
 * 关联写入（create/delete/attach/detach）单元测试
 *
 * 基于真实 SQLite 文件库执行实际 SQL，覆盖：
 * 一对一/一对多的关联新增与关联删除（含软删除透传、列白名单、
 * DataSet 父级形态、外键强制覆盖），多对多的 attach 幂等绑定
 * 与 detach 定向/全量解绑，以及多对多误用 create/delete 的拦截。
 */
class RelationWriteTest extends TestCase
{
  /** @var string 测试模型共用的通道名（同一 SQLite 文件） */
  private const CHANNEL = 'rw_db';
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
    if (self::$tmpDb !== null && is_file(self::$tmpDb)) unlink(self::$tmpDb);
    self::$tmpDb = null;
  }

  /**
   * 一对多关联新增：外键自动绑定父键，返回含主键的 DataSet 并落库
   */
  public function testHasManyCreateBindsForeignKey(): void
  {
    $user = new RwUserModel();
    $result = $user->articles()->create(1, ['title' => '新文章', 'user_id' => 999]);
    self::assertInstanceOf(DataSet::class, $result);
    self::assertGreaterThan(0, $result['id'], '返回结果应包含自增主键');
    // 调用方传入的外键值必须被框架覆盖，防止越权绑定
    $row = $this->fetchOne('articles', $result['id']);
    self::assertSame(1, $row['user_id'], '外键应强制写入父键而非调用方传入值');
    self::assertSame('新文章', $row['title']);
  }

  /**
   * 一对多关联新增：$parent 支持主表行数据集（DataSet）形态
   */
  public function testHasManyCreateWithDataSetParent(): void
  {
    $user = RwUserModel::find(2);
    (new RwUserModel())->articles()->create($user, ['title' => 'u2 文章']);
    $row = $this->fetchOne('articles', null, "title = 'u2 文章'");
    self::assertSame(2, $row['user_id']);
  }

  /**
   * 一对多关联新增：columns 白名单过滤越权列
   */
  public function testHasManyCreateFiltersColumns(): void
  {
    (new RwUserModel())->articles()->create(
      1, ['title' => 'x', 'evil' => 'hack'], ['title']
    );
    $row = $this->fetchOne('articles', null, "title = 'x'");
    // evil 不在白名单内，不应进入写入流程（articles 表无该列，未过滤会直接报错）
    self::assertSame(1, $row['user_id']);
    self::assertSame('x', $row['title']);
  }

  /**
   * 一对多关联删除：按外键删除全部关联行，其他父级数据不受影响
   */
  public function testHasManyDeleteRemovesAllRelated(): void
  {
    $count = (new RwUserModel())->articles()->delete(1);
    self::assertSame(2, $count, '应删除 u1 的两条文章');
    self::assertSame(0, $this->countRows('articles', 'user_id = 1'), 'u1 文章应全部删除');
    self::assertSame(1, $this->countRows('articles', 'user_id = 2'), 'u2 文章应保留');
  }

  /**
   * 一对一关联新增：行为与一对多一致，外键自动绑定
   */
  public function testHasOneCreateBindsForeignKey(): void
  {
    (new RwUserModel())->profile()->create(2, ['bio' => '简介']);
    $row = $this->fetchOne('profiles', null, "bio = '简介'");
    self::assertSame(2, $row['user_id']);
  }

  /**
   * 一对一关联删除：默认软删除（写 delete_time），$real = true 硬删除
   */
  public function testHasOneDeleteSoftDeletePassthrough(): void
  {
    $user = new RwUserModel();
    // 第一次：软删除，行仍在但 delete_time 被写入
    $user->profile()->delete(1);
    $row = $this->fetchOne('profiles', null, 'user_id = 1');
    self::assertNotNull($row, '软删除后物理行应保留');
    self::assertNotNull($row['delete_time'], '软删除标记应被写入');
    // 第二次：硬删除，物理行移除
    $user->profile()->delete(1, true);
    self::assertSame(0, $this->countRows('profiles', 'user_id = 1'), '硬删除后行应移除');
  }

  /**
   * attach 新增绑定：已存在的跳过，缺失的批量写入并附加 pivot 字段
   */
  public function testAttachSkipsExistingAndWritesMissing(): void
  {
    $user = new RwUserModel();
    // u1-10 已预置，u1-12 为新绑定
    $count = $user->roles()->attach(1, [10, 12], ['bind_time' => '2026-02-01']);
    self::assertSame(1, $count, '仅应新增 1 条绑定');
    $newRow = $this->fetchOne('role_user', null, "user_id = 1 AND role_id = 12");
    self::assertSame('2026-02-01', $newRow['bind_time'], 'pivotData 应合并进新绑定');
    // 幂等：重复 attach 相同绑定返回 0 且不产生重复行
    $again = $user->roles()->attach(1, [12]);
    self::assertSame(0, $again);
    self::assertSame(1, $this->countRows('role_user', 'user_id = 1 AND role_id = 12'));
  }

  /**
   * attach 支持标量关联键与 DataSet 父级形态
   */
  public function testAttachScalarAndDataSetParent(): void
  {
    $user = RwUserModel::find(2);
    $count = (new RwUserModel())->roles()->attach($user, 10, ['bind_time' => '2026-02-02']);
    self::assertSame(1, $count);
    self::assertSame(1, $this->countRows('role_user', 'user_id = 2 AND role_id = 10'));
  }

  /**
   * detach 定向解绑与全量解绑
   */
  public function testDetachSpecificAndAll(): void
  {
    $user = new RwUserModel();
    // 定向：仅解除 u1-10
    $count = $user->roles()->detach(1, [10]);
    self::assertSame(1, $count);
    self::assertSame(0, $this->countRows('role_user', 'user_id = 1 AND role_id = 10'));
    self::assertSame(1, $this->countRows('role_user', 'user_id = 1 AND role_id = 11'), '未指定的绑定应保留');
    // 全量：为 u2 补两条绑定后全部解除
    $user->roles()->attach(2, [10, 11]);
    $all = $user->roles()->detach(2);
    self::assertSame(2, $all, 'u2 两条绑定应全部解除');
    self::assertSame(0, $this->countRows('role_user', 'user_id = 2'));
  }

  /**
   * attach 空关联键被拦截；父级 DataSet 缺少关联键被拦截
   */
  public function testAttachRejectsEmptyRelatedKeys(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('关联键不能为空');
    (new RwUserModel())->roles()->attach(1, []);
  }

  public function testCreateRejectsParentKeyMissing(): void
  {
    $broken = new DataSet((new RwArticleModel())->query, ['title' => '无主行']);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('缺少关联键');
    (new RwUserModel())->articles()->create($broken, ['title' => 'x']);
  }

  /**
   * 多对多误用 create()/delete() 被明确拦截并提示正确方法
   */
  public function testBelongsToManyRejectsMisusedCreateAndDelete(): void
  {
    $roles = (new RwUserModel())->roles();
    try {
      $roles->create(1, ['name' => 'x']);
      self::fail('多对多调用 create() 应抛出异常');
    } catch (\InvalidArgumentException $e) {
      self::assertStringContainsString('attach()', $e->getMessage());
    }
    try {
      $roles->delete(1);
      self::fail('多对多调用 delete() 应抛出异常');
    } catch (\InvalidArgumentException $e) {
      self::assertStringContainsString('detach()', $e->getMessage());
    }
  }

  /**
   * sync 多退少补：新增缺失绑定、移除多余绑定，交集保持不变
   */
  public function testSyncAttachesMissingAndDetachesExtra(): void
  {
    $user = new RwUserModel();
    // 夹具预置 u1 绑定 [10, 11]，对齐目标 [10, 20]：新增 20、移除 11、保留 10
    $result = $user->roles()->sync(1, [10, 20], ['bind_time' => '2026-03-01']);
    self::assertSame([20], $result['attached'], '应新增目标中缺失的绑定');
    self::assertSame([11], $result['detached'], '应移除不在目标中的绑定');
    // 落库状态：u1 的绑定应精确对齐为 10、20
    self::assertSame(2, $this->countRows('role_user', 'user_id = 1'));
    self::assertSame(1, $this->countRows('role_user', 'user_id = 1 AND role_id = 10'));
    self::assertSame(1, $this->countRows('role_user', 'user_id = 1 AND role_id = 20'));
    self::assertSame(0, $this->countRows('role_user', 'user_id = 1 AND role_id = 11'));
    // 新增绑定的 pivotData 已合并，既有绑定不受影响
    $newRow = $this->fetchOne('role_user', null, 'user_id = 1 AND role_id = 20');
    self::assertSame('2026-03-01', $newRow['bind_time']);
    $keptRow = $this->fetchOne('role_user', null, 'user_id = 1 AND role_id = 10');
    self::assertSame('2026-01-01', $keptRow['bind_time'], '交集绑定的中间表数据应保持不变');
    // 其他父级的绑定不受影响
    $user->roles()->attach(2, 10);
    $user->roles()->sync(1, [10, 20]);
    self::assertSame(1, $this->countRows('role_user', 'user_id = 2'), 'u2 的绑定不应被误删');
  }

  /**
   * sync 目标为空数组：清空该父级全部绑定
   */
  public function testSyncWithEmptyTargetClearsAll(): void
  {
    $result = (new RwUserModel())->roles()->sync(1, []);
    self::assertSame([], $result['attached']);
    self::assertSame([10, 11], $result['detached'], 'u1 的两条预置绑定应全部移除');
    self::assertSame(0, $this->countRows('role_user', 'user_id = 1'));
  }

  /**
   * sync 目标与现状一致：不产生任何写入，返回空结果
   */
  public function testSyncWithIdenticalTargetIsNoop(): void
  {
    $result = (new RwUserModel())->roles()->sync(1, [11, 10]);
    self::assertSame([], $result['attached'], '无缺失绑定应不新增');
    self::assertSame([], $result['detached'], '无多余绑定应不移除');
    self::assertSame(2, $this->countRows('role_user', 'user_id = 1'), '绑定状态应原样保留');
  }

  /**
   * 创建临时 SQLite 文件库并初始化关联写入测试数据
   *
   * 数据约定：u1 有两篇文章与一条档案（软删除模型）、u2 一篇文章；
   * role_user 预置 u1-10、u1-11 两条绑定。
   *
   * @return string 数据库文件路径
   */
  private function makeSqliteFile(): string
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_rw_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT)');
    $pdo->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT, delete_time TEXT)');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER, bind_time TEXT)');
    $this->insertRows($pdo, 'users', ['id', 'name'], [[1, 'u1'], [2, 'u2']]);
    $this->insertRows($pdo, 'articles', ['id', 'user_id', 'title'], [
      [1, 1, 'a1'], [2, 1, 'a2'], [3, 2, 'b1'],
    ]);
    $this->insertRows($pdo, 'profiles', ['id', 'user_id', 'bio'], [[1, 1, 'p1']]);
    $this->insertRows($pdo, 'roles', ['id', 'name'], [[10, 'admin'], [11, 'editor'], [20, 'viewer']]);
    $this->insertRows($pdo, 'role_user', ['user_id', 'role_id', 'bind_time'], [
      [1, 10, '2026-01-01'], [1, 11, '2026-01-02'],
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

  /**
   * 用独立连接按主键取单行（主键为 null 时按条件取）
   *
   * @param string $table 表名
   * @param int|string|null $id 主键值
   * @param string $condition 额外查询条件（不含 WHERE）
   * @return array 行数据
   */
  private function fetchOne(string $table, int|string|null $id, string $condition = '1=1'): array
  {
    $sql = $id !== null ? "SELECT * FROM $table WHERE id = $id" : "SELECT * FROM $table WHERE $condition";
    $row = (new PDO('sqlite:' . self::$tmpDb))->query($sql)->fetch(PDO::FETCH_ASSOC);
    self::assertIsArray($row, "应查询到 $table 的行数据");
    return $row;
  }

  /**
   * 用独立连接统计满足条件的行数
   *
   * @param string $table 表名
   * @param string $condition 查询条件（不含 WHERE）
   * @return int 行数
   */
  private function countRows(string $table, string $condition): int
  {
    return (int)(new PDO('sqlite:' . self::$tmpDb))
      ->query("SELECT COUNT(*) FROM $table WHERE $condition")->fetchColumn();
  }
}

/**
 * 关联写入测试主模型：users 表，声明一对多/一对一/多对多三类关联
 */
class RwUserModel extends Model
{
  protected string $table = 'users';
  protected ?string $channelName = 'rw_db';

  /**
   * 一对多：用户的文章
   */
  public function articles(): RelationQuery
  {
    return $this->hasMany(RwArticleModel::class, 'user_id', 'id');
  }

  /**
   * 一对一：用户的档案（启用软删除，验证 $real 透传）
   */
  public function profile(): RelationQuery
  {
    return $this->hasOne(RwProfileModel::class, 'user_id', 'id');
  }

  /**
   * 多对多：用户的角色
   */
  public function roles(): BelongsToMany
  {
    return $this->belongsToMany(RwRoleModel::class, 'role_user', 'user_id', 'role_id');
  }
}

/**
 * 关联写入测试模型：articles 表
 */
class RwArticleModel extends Model
{
  protected string $table = 'articles';
  protected ?string $channelName = 'rw_db';
}

/**
 * 关联写入测试模型：profiles 表（启用软删除）
 */
class RwProfileModel extends Model
{
  protected string $table = 'profiles';
  protected ?string $channelName = 'rw_db';
  protected bool $enableSoftDelete = true;
}

/**
 * 关联写入测试模型：roles 表
 */
class RwRoleModel extends Model
{
  protected string $table = 'roles';
  protected ?string $channelName = 'rw_db';
}
