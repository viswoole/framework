<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Viswoole\Core\App;
use Viswoole\Database\DbManager;
use Viswoole\Database\Entity;
use Viswoole\Database\Entity\EntityCollection;
use Viswoole\Database\Facade\Db as DbFacade;
use Viswoole\Database\Model;
use Viswoole\Database\Model\BelongsToMany;
use function Swoole\Coroutine\run;

/**
 * 批次 2（模型与实体层）加固回归测试
 *
 * 覆盖三项中危修复：
 *  1. B2-01 复用 Query 实例时关联注册应随执行消费后清空，防止后续无关查询
 *     意外携带旧关联数据（关联信息暴露 + 性能损耗）
 *  2. B2-03 attach() 幂等检查应对关联键做类型归一（PDO 模拟预处理下 int 列
 *     返回字符串，严格比较恒 false 导致重复绑定）
 *  3. B2-02 实体集合批量 update 的内存同步应与落库路径一致地应用修改器
 */
class HardeningBatch2Test extends TestCase
{
  /** @var string 模型测试共用的通道名（SQLite 文件库） */
  private const CHANNEL = 'h2_db';
  /** @var string|null 当前用例的临时库文件路径 */
  private static ?string $tmpDb = null;

  protected function setUp(): void
  {
    self::$tmpDb = $this->makeSqliteFile();
    $db = App::factory()->make(DbManager::class);
    $db->setDebug(false);
    $db->addChannel(self::CHANNEL, $this->makeSqliteChannel(self::$tmpDb));
  }

  protected function tearDown(): void
  {
    if (self::$tmpDb !== null && is_file(self::$tmpDb)) unlink(self::$tmpDb);
    self::$tmpDb = null;
  }

  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志（避免 PHPUnit 标记 risky）
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
   * 创建临时 SQLite 文件库并初始化用户/角色/中间表
   *
   * 数据约定：u1 已绑定角色 10。
   */
  private function makeSqliteFile(): string
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_h2_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
    $pdo->exec("INSERT INTO users VALUES (1, 'u1'), (2, 'u2')");
    $pdo->exec("INSERT INTO roles VALUES (10, 'admin'), (11, 'editor')");
    $pdo->exec("INSERT INTO role_user VALUES (1, 10)");
    return $tmpDb;
  }

  private function makeSqliteChannel(string $tmpDb): \Viswoole\Database\Channel\PDO\PDOChannel
  {
    return new \Viswoole\Database\Channel\PDO\PDOChannel(
      type: \Viswoole\Database\Channel\PDO\DriverType::SQLite,
      database: $tmpDb
    );
  }

  // ---------------------------------------------------------------- B2-01

  /**
   * 复用 Query 实例的第二次查询不应携带首次 with() 注册的关联
   */
  public function testResetClearsRegisteredRelations(): void
  {
    $query = (new H2UserModel())->query;
    $this->runQuietly(function () use ($query) {
      $first = $query->with(['books'])->getArray();
      self::assertArrayHasKey('books', $first[0], '首次查询应填充关联数据');

      $second = $query->getArray();
      self::assertArrayNotHasKey('books', $second[0], 'reset 后复用实例不应再携带旧关联');
    });
  }

  // ---------------------------------------------------------------- B2-03

  /**
   * attach() 对 string 关联键与 int 库值应视为同一绑定（幂等检查类型归一）
   */
  public function testAttachTreatsStringKeyAsBound(): void
  {
    $relation = (new H2UserModel())->books();
    $this->runQuietly(function () use ($relation) {
      $inserted = $relation->attach(1, '10');
      self::assertSame(0, $inserted, '已存在的绑定（库中 int 10）不应因入参为 string 而重复插入');

      $pdo = new PDO('sqlite:' . self::$tmpDb);
      $count = (int)$pdo->query(
        "SELECT COUNT(*) FROM role_user WHERE user_id = 1 AND role_id = 10"
      )->fetchColumn();
      self::assertSame(1, $count, '绑定记录应保持唯一');
    });
  }

  // ---------------------------------------------------------------- B2-02

  /**
   * 实体集合批量 update 的内存同步应应用修改器，与落库值保持一致
   */
  public function testEntityCollectionUpdateAppliesMutatorsInMemory(): void
  {
    $db = App::factory()->make(DbManager::class);
    // 影响行数固定为 2（两条主键匹配的行），聚焦内存同步的修改器行为
    $db->addChannel('default', new FakeChannel(2));
    $db->setDebug(false);

    $entity = new H2MutatorEntity();
    $collection = new EntityCollection(
      $entity->query()->where('id', '>', 0),
      [
        ['id' => 1, 'user_name' => 'alice'],
        ['id' => 2, 'user_name' => 'bob'],
      ]
    );

    $this->runQuietly(function () use ($collection) {
      $affected = $collection->update(['user_name' => 'carol']);
      self::assertSame(2, $affected);
    });

    // 落库值经修改器转换为大写；内存实体应与库值一致
    self::assertSame('CAROL', $collection[0]->userName, '内存同步应与落库路径一致地应用修改器');
  }

  /**
   * 批量更新携带 Raw 表达式：内存同步应跳过该字段而非把 Raw 传给修改器/水合器
   */
  public function testEntityCollectionUpdateSkipsRawValues(): void
  {
    $db = App::factory()->make(DbManager::class);
    $db->addChannel('default', new FakeChannel(2));
    $db->setDebug(false);

    $entity = new H2MutatorEntity();
    $collection = new EntityCollection(
      $entity->query()->where('id', '>', 0),
      [
        ['id' => 1, 'user_name' => 'alice', 'age' => 20],
        ['id' => 2, 'user_name' => 'bob', 'age' => 30],
      ]
    );

    $this->runQuietly(function () use ($collection) {
      $affected = $collection->update([
        'user_name' => 'carol',
        'age' => DbFacade::raw('age + 1'),
      ]);
      self::assertSame(2, $affected);
    });

    // Raw 字段留待下次查询水合，不应把 Raw 传入修改器/类型强转而崩溃
    self::assertSame('CAROL', $collection[0]->userName, '标量字段仍应正常同步修改器结果');
    self::assertSame(20, $collection[0]->age, 'Raw 字段应跳过内存同步');
  }

  /**
   * chunk 路径同样消费已注册关联：生成器终结后复用实例不应再携带旧关联
   *
   * 注：chunk 本身不填充关联数据（不经过 runCrud 的关联填充），但注册的
   * 关联应随生成器终结被清空，避免残留到同实例的后续查询
   */
  public function testChunkConsumesRegisteredRelations(): void
  {
    $query = (new H2UserModel())->query;
    $this->runQuietly(function () use ($query) {
      foreach ($query->with(['books'])->chunk(1) as $chunk) {
        self::assertArrayNotHasKey('books', $chunk[0], 'chunk 路径不填充关联数据');
      }

      $rows = $query->getArray();
      self::assertArrayNotHasKey('books', $rows[0], 'chunk 终结后复用实例不应再携带旧关联');
    });
  }

  // ---------------------------------------------------------------- B2-04

  /**
   * 协程数达 max_cor_num 上限时关联并发查询不得永久阻塞
   *
   * Coroutine::create 超限返回 false 且闭包不执行（wg->done() 永不调用）：
   * 修复前 $wg->wait() 会永久阻塞挂死请求。修复后该关联降级为串行查询，
   * 结果正常填充。用阻塞在 Channel 上的占位协程打满配额（同步 I/O 协程
   * 在 create 内执行完毕不占名额，无法靠普通查询触发），压 max_cor_num=2：
   * run 主协程 + 占位协程 = 2，关联查询的 create 必然超限。
   *
   * 注：Swoole 6.x 已不在 create 时强制 max_cor_num 配额（实测 6.2.1 超限
   * 仍创建成功），本用例在 6.x 走正常并发路径、在 5.x 强制走串行降级路径，
   * 两个版本下均断言查询完成且关联填充正确
   */
  public function testRelationQueryDegradesToSerialWhenCoroutineQuotaExhausted(): void
  {
    Coroutine::set(['max_cor_num' => 2]);
    try {
      run(function () {
        $blocker = new Channel();
        // 占住第 2 个协程名额：后续 Coroutine::create 达到 max_cor_num 上限
        Coroutine::create(function () use ($blocker) {
          $blocker->pop();
        });
        try {
          $this->runQuietly(function () {
            $rows = (new H2UserModel())->query->with(['books'])->getArray();
            self::assertNotEmpty($rows, '主表数据应正常返回');
            self::assertArrayHasKey(
              'books',
              $rows[0],
              '协程配额打满时关联查询应降级串行完成而非永久阻塞'
            );
          });
        } finally {
          // 释放占位协程，保证 run() 正常退出
          $blocker->push(1);
        }
      });
    } finally {
      // 恢复进程级协程配额，避免污染后续用例
      Coroutine::set(['max_cor_num' => 100000]);
    }
  }
}

/**
 * 批次 2 测试主模型：users 表，books 关联指向 roles 表
 */
class H2UserModel extends Model
{
  protected string $table = 'users';
  protected ?string $channelName = 'h2_db';

  public function books(): BelongsToMany
  {
    return $this->belongsToMany(H2BookModel::class, 'role_user', 'user_id', 'role_id');
  }
}

/**
 * 批次 2 测试关联模型：roles 表
 */
class H2BookModel extends Model
{
  protected string $table = 'roles';
  protected ?string $channelName = 'h2_db';
}

/**
 * 批次 2 测试实体：user_name 带修改器（模拟 password 哈希类转换场景）
 */
class H2MutatorEntity extends Entity
{
  protected string $table = 'users';

  public int $id;
  public string $userName;
  public int $age = 0;

  /**
   * 修改器：写入前统一转大写（验证落库与内存同步的一致性）
   *
   * @param mixed $value 原始值
   * @return string 转换后的值
   */
  public function setUserNameAttr(mixed $value): string
  {
    return strtoupper((string)$value);
  }
}
