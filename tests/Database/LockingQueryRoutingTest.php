<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use function Swoole\Coroutine\run;
use Viswoole\Core\App;
use Viswoole\Database\Channel;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Channel\PDO\PDOProxy;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;
use Override;

/**
 * 锁定读与事务连接的主库路由测试
 *
 * 读写分离场景下的正确性约定：
 * 1. 锁定读（FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE 等各驱动锁定语法）
 *    必须路由到写库（主库）——从库上的行锁无法与主库写入者互斥，锁语义只在主库成立；
 * 2. 事务连接一律取写库——事务连接由首条语句的 pop 触发建立，
 *    若按语句读写类型定池，首条为普通读的事务将从读池取连接并复用至事务结束，
 *    后续写入将落在从库（只读库报错、可写库失去主库互斥语义）。
 */
class LockingQueryRoutingTest extends TestCase
{
  protected function setUp(): void
  {
    App::factory()->make(DbManager::class)->setDebug(false);
  }

  /**
   * isLockingQuery 判定：大小写不敏感、多空白、WITH CTE、共享锁均命中；
   * 普通读写语句不命中（列名 for_update 等下划线连写不构成词边界，不误报）
   */
  public function testIsLockingQueryDetection(): void
  {
    // 大写/小写/多空白均命中
    self::assertTrue(Channel::isLockingQuery('SELECT * FROM t WHERE id = 1 FOR UPDATE'));
    self::assertTrue(Channel::isLockingQuery('select * from t where id = 1 for update'));
    self::assertTrue(Channel::isLockingQuery("SELECT * FROM t\nWHERE id = 1\nFOR   UPDATE"));
    // 共享锁命中
    self::assertTrue(Channel::isLockingQuery('SELECT * FROM t WHERE id = 1 LOCK IN SHARE MODE'));
    self::assertTrue(Channel::isLockingQuery('select * from t lock in share mode'));
    // WITH CTE 形态的锁定读命中（首关键字 WITH 仍判读，但含 FOR UPDATE 须路由写库）
    self::assertTrue(Channel::isLockingQuery('WITH c AS (SELECT * FROM t) SELECT * FROM c FOR UPDATE'));
    // MySQL 8.0.1+ FOR SHARE（LOCK IN SHARE MODE 的等价别名）命中
    self::assertTrue(Channel::isLockingQuery('SELECT * FROM t WHERE id = 1 FOR SHARE'));
    self::assertTrue(Channel::isLockingQuery('select * from t where id = 1 for share nowait'));
    // PostgreSQL 锁定读子句命中
    self::assertTrue(Channel::isLockingQuery('SELECT * FROM t WHERE id = 1 FOR NO KEY UPDATE'));
    self::assertTrue(Channel::isLockingQuery('SELECT * FROM t WHERE id = 1 FOR KEY SHARE'));
    // SQL Server UPDLOCK 表提示命中
    self::assertTrue(Channel::isLockingQuery('SELECT * FROM t WITH (UPDLOCK, ROWLOCK) WHERE id = 1'));
    // 普通读写语句不命中
    self::assertFalse(Channel::isLockingQuery('SELECT * FROM t'));
    self::assertFalse(Channel::isLockingQuery('SELECT * FROM t WHERE status = 1 ORDER BY id'));
    self::assertFalse(Channel::isLockingQuery('INSERT INTO t (v) VALUES (?)'));
    self::assertFalse(Channel::isLockingQuery('UPDATE t SET v = 1 WHERE id = 1'));
    // 下划线连写（如列名 for_update）不构成词边界，不误报
    self::assertFalse(Channel::isLockingQuery('SELECT for_update FROM t'));
    self::assertFalse(Channel::isLockingQuery('SELECT for_share FROM t'));
    self::assertFalse(Channel::isLockingQuery('SELECT * FROM t WHERE locked_in_share_mode = 1'));
    // 普通 CTE（WITH 后不紧跟括号）不构成 SQL Server 表提示，不误报
    self::assertFalse(Channel::isLockingQuery('WITH c AS (SELECT 1) SELECT * FROM c'));
  }

  /**
   * 事务内新取连接应强制走写库：即使首条语句按类型传入 read
   * （事务连接由首条语句 pop 触发建立，定池不可依赖语句读写类型）；
   * 复用分支不再触发通道 pop，popTypes 不增长
   */
  public function testTransactionConnectionAlwaysPopsWritePool(): void
  {
    $bag = new \ArrayObject(['popTypes' => []]);
    $channel = new class($bag) extends Channel {
      public function __construct(private readonly \ArrayObject $bag)
      {
      }

      public function execute(
        string|Raw   $sql,
        array        $bindings = [],
        false|string $getId = false,
        bool         $master = false
      ): \PDOStatement|int|string {
        return 0;
      }

      public function pop(string $type): mixed
      {
        $this->bag['popTypes'][] = $type;
        // 返回真实 SQLite 连接以满足 ConnectManager 的 beginTransaction 调用
        return new PDO('sqlite::memory:');
      }

      public function put(mixed $connect): void
      {
      }

      public function build(Options $options): Raw
      {
        return new Raw('');
      }
    };

    run(function () use ($channel): void {
      $manager = ConnectManager::factory();
      $manager->start();
      // 首条语句为普通读（read 类型请求）：事务连接仍应取写库
      $conn = $manager->pop($channel, 'read');
      // 事务内 put 仅标记空闲（不走真实归还），再次 pop 命中复用分支，
      // 不再向通道取连接
      $manager->put($channel, $conn);
      $manager->pop($channel, 'read');
      $manager->rollBack();
    });

    self::assertSame(
      ['write'],
      $bag['popTypes'],
      '事务内新取连接应强制 write，忽略语句的读写类型标记'
    );
  }

  /**
   * 非事务路径的 pop 类型应按传入值透传（锁读强制写库由 PDOChannel 路由层
   * 负责，通道自身不篡改调用方类型语义；此处锁定 ConnectManager 不误改）
   */
  public function testNonTransactionPopTypePassThrough(): void
  {
    $bag = new \ArrayObject(['popTypes' => []]);
    $channel = new class($bag) extends Channel {
      public function __construct(private readonly \ArrayObject $bag)
      {
      }

      public function execute(
        string|Raw   $sql,
        array        $bindings = [],
        false|string $getId = false,
        bool         $master = false
      ): \PDOStatement|int|string {
        return 0;
      }

      public function pop(string $type): mixed
      {
        $this->bag['popTypes'][] = $type;
        return null;
      }

      public function put(mixed $connect): void
      {
      }

      public function build(Options $options): Raw
      {
        return new Raw('');
      }
    };

    run(function () use ($channel): void {
      ConnectManager::factory()->pop($channel, 'read');
      ConnectManager::factory()->pop($channel, 'write');
    });

    self::assertSame(['read', 'write'], $bag['popTypes'], '非事务路径应按调用方类型透传');
  }

  /**
   * PDOChannel::execute 路由接线：锁定读（含 FOR SHARE）与 master=true
   * 强制从写库取连接，普通读按语句类型走读库——
   * 守护 isLockingQuery 判定结果与连接类型参数之间的核心分支
   */
  public function testExecuteRoutesLockingQueryToWritePool(): void
  {
    $bag = new \ArrayObject(['popTypes' => []]);
    $statement = $this->createMock(\PDOStatement::class);
    $proxy = $this->createMock(PDOProxy::class);
    // PDOProxy 经 __call 魔术方法转发 prepare：stub 返回语句 mock，
    // 使锁定读语法无需真实数据库即可走通 execute 全流程
    // （闭包仅声明 $name 形参，PHP 允许闭包接收多余实参）
    $proxy->method('__call')->willReturnCallback(
      static fn(string $name): mixed => $name === 'prepare' ? $statement : null
    );

    $channel = new class($bag, $proxy) extends PDOChannel {
      public function __construct(
        private readonly \ArrayObject $bag,
        private readonly PDOProxy    $proxy
      )
      {
      }

      // 覆写 pop 记录路由类型并返回代理 mock，绕开真实连接池
      #[Override] public function pop(string $type): PDOProxy
      {
        $this->bag['popTypes'][] = $type;
        return $this->proxy;
      }

      #[Override] public function put(mixed $connect): void
      {
      }
    };

    run(function () use ($channel): void {
      $channel->execute('SELECT * FROM t WHERE id = 1 FOR UPDATE');
      $channel->execute('SELECT * FROM t WHERE id = 1 FOR SHARE');
      $channel->execute('SELECT * FROM t WHERE id = 1');
      $channel->execute('INSERT INTO t (v) VALUES (?)', [1], false, true);
    });

    self::assertSame(
      ['write', 'write', 'read', 'write'],
      $bag['popTypes'],
      '锁定读（FOR UPDATE / FOR SHARE）与 master=true 应路由写库，普通读路由读库'
    );
  }
}
