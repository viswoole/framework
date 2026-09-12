<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;
use function Swoole\Coroutine\run;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\DbManager;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model;
use Viswoole\Database\Model\BelongsToMany;

/**
 * 多对多 sync() 事务完整性测试
 *
 * sync() 内部为两步写入（新增绑定 + 移除绑定），若未包裹事务，
 * 移除步骤失败时新增步骤已落库，绑定关系错乱：
 * 期望失败时整体回滚，中间表恢复到 sync 前状态。
 */
class PivotSyncTransactionTest extends TestCase
{
  /** @var string 通道名 */
  public const CHANNEL = 'sync_tx_db';

  /** @var string|null 当前用例的临时库文件路径 */
  private static ?string $tmpDb = null;

  protected function setUp(): void
  {
    self::$tmpDb = $this->makeSqliteFile();
    $db = App::factory()->make(DbManager::class);
    $db->setDebug(false);
    $db->addChannel(self::CHANNEL, new PDOChannel(
      type: DriverType::SQLite, database: self::$tmpDb
    ));
  }

  protected function tearDown(): void
  {
    if (self::$tmpDb !== null && is_file(self::$tmpDb)) unlink(self::$tmpDb);
    self::$tmpDb = null;
  }

  /**
   * sync() 移除绑定失败时，新增的绑定应随事务一并回滚
   *
   * 通过 SQLite 触发器强制 DELETE 失败：
   * sync(1, [20]) 应移除已有绑定 10、新增绑定 20；移除 10 被触发器中断后，
   * 新增的 20 必须回滚，中间表保持仅有 (1,10)
   */
  public function testSyncRollsBackWhenDetachFails(): void
  {
    $relation = new BelongsToMany(
      new PivotSyncRoleModel(),
      new PivotSyncRoleUserModel(),
      'user_id', 'role_id', 'id', 'id'
    );

    // 协程内捕获异常（异常跨协程传播行为不稳定，协程内捕获最可靠）
    $caught = null;
    run(function () use ($relation, &$caught): void {
      try {
        // 目标集合不含既有绑定 10：新增 (1,20) 成功后，移除 (1,10) 被触发器中断
        $relation->sync(1, [20]);
      } catch (Throwable $e) {
        $caught = $e;
      }
    });

    static::assertInstanceOf(
      DbException::class,
      $caught,
      '移除绑定失败应抛出 DbException（触发器 ABORT）'
    );

    // 回滚后校验最终状态：新增的 (1,20) 必须消失，仅保留原有绑定
    $rows = (new PDO('sqlite:' . self::$tmpDb))
      ->query('SELECT user_id, role_id FROM role_user ORDER BY role_id')
      ->fetchAll(PDO::FETCH_ASSOC);

    self::assertSame(
      [['user_id' => 1, 'role_id' => 10]],
      $rows,
      'sync 失败后新增绑定应回滚，仅保留原有绑定'
    );
  }

  /**
   * 创建临时 SQLite 库：role_user 中间表 + 强制 DELETE 失败的触发器
   */
  private function makeSqliteFile(): string
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $tmpDb = sys_get_temp_dir() . '/viswoole_sync_tx_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
    $pdo->exec("INSERT INTO roles VALUES (10, 'admin')");
    $pdo->exec('INSERT INTO role_user VALUES (1, 10)');
    // 强制 DELETE 失败：模拟 sync 第二步（移除绑定）中途出错
    $pdo->exec(
      "CREATE TRIGGER block_delete BEFORE DELETE ON role_user
       BEGIN SELECT RAISE(ABORT, 'forced delete failure'); END;"
    );
    return $tmpDb;
  }
}

/**
 * 关联角色模型（roles 表）
 */
class PivotSyncRoleModel extends Model
{
  protected string $table = 'roles';
  protected ?string $channelName = PivotSyncTransactionTest::CHANNEL;
}

/**
 * 中间表模型（role_user 表）
 */
class PivotSyncRoleUserModel extends Model
{
  protected string $table = 'role_user';
  protected ?string $channelName = PivotSyncTransactionTest::CHANNEL;
}
