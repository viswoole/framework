<?php

declare(strict_types=1);

/* 职责：验证事务提交后回调的嵌套归属、失败隔离和真实提交时机，不访问业务服务。 */

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\Channel;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Exception\DbException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel as CoroutineChannel;

use function Swoole\Coroutine\run;

/**
 * 提交后回调契约测试
 */
final class AfterCommitTest extends TestCase
{
  /**
   * @var ConnectManager 当前用例独立事务管理器
   */
  private ConnectManager $manager;

  /**
   * @var DbManager 真实公共事务入口
   */
  private DbManager $database;

  /**
   * @var mixed 调用方原事务上下文
   */
  private mixed $previousManager;

  /**
   * 建立隔离事务入口
   *
   * 不初始化数据库配置，保留真实事务方法及协程上下文解析。
   *
   * @return void 独立上下文已就绪
   */
  protected function setUp(): void
  {
    $this->previousManager = Context::get('$_db_transaction');
    $this->manager = new ConnectManager();
    Context::set('$_db_transaction', $this->manager);
    $this->database = (new ReflectionClass(DbManager::class))->newInstanceWithoutConstructor();
    self::assertTrue(method_exists($this->database, 'afterCommit'), '事务入口缺少 afterCommit 回调能力');
  }

  /**
   * 清理未结束事务
   *
   * 用例异常也须释放回调及连接，不能影响后续测试。
   *
   * @return void 原上下文已恢复
   */
  protected function tearDown(): void
  {
    while ($this->manager->transactionLevel() > 0) { $this->manager->rollBack(); }
    Context::set('$_db_transaction', $this->previousManager);
  }

  /**
   * 嵌套提交不提前执行回调
   *
   * 最外层提交后按注册顺序执行，连接及事务状态须已释放。
   *
   * @return void 合并时机及顺序正确
   */
  public function testInnerCommitWaitsForOuterCommit(): void
  {
    $events = [];
    $this->database->startTransaction(function () use (&$events): void {
      $this->database->afterCommit(function () use (&$events): void { $events[] = 'outer'; });
      $this->database->startTransaction(function () use (&$events): void {
        $this->database->afterCommit(function () use (&$events): void {
          $events[] = $this->database->transactionLevel();
          $events[] = 'inner';
        });
      });
      self::assertSame([], $events);
      $this->database->afterCommit(function () use (&$events): void { $events[] = 'last'; });
    });
    self::assertSame(['outer', 0, 'inner', 'last'], $events);
  }

  /**
   * 内层回滚只删除内层回调
   *
   * 同层重新开启事务不得捡回已经回滚的回调。
   *
   * @return void 作用域隔离正确
   */
  public function testInnerRollbackDiscardsItsCallbacksOnly(): void
  {
    $events = [];
    $this->database->startTransaction();
    $this->database->afterCommit(function () use (&$events): void { $events[] = 1; });
    $this->database->startTransaction();
    $this->database->afterCommit(function () use (&$events): void { $events[] = 2; });
    $this->database->rollBack();
    $this->database->startTransaction();
    $this->database->afterCommit(function () use (&$events): void { $events[] = 3; });
    $this->database->commit();
    $this->database->commit();
    self::assertSame([1, 3], $events);
  }

  /**
   * 外层回滚删除已合并回调
   *
   * 内层提交不代表持久化成功，不能触发外部通知。
   *
   * @return void 回调全部丢弃
   */
  public function testOuterRollbackDiscardsCommittedInnerCallbacks(): void
  {
    $called = false;
    $this->database->startTransaction();
    $this->database->startTransaction();
    $this->database->afterCommit(function () use (&$called): void { $called = true; });
    $this->database->commit();
    $this->database->rollBack();
    $this->database->startTransaction(fn (): null => null);
    self::assertFalse($called);
  }

  /**
   * 回调故障不改变真实提交结果
   *
   * 后续回调继续执行，已提交数据保持落库，闭包返回值原样保留。
   *
   * @return void 数据、回调及返回值正确
   */
  public function testCallbackFailureCannotUndoCommittedData(): void
  {
    $connection = new PDO('sqlite::memory:');
    $connection->exec('CREATE TABLE evidence(id INTEGER PRIMARY KEY)');
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    $called = null;
    $result = $this->database->startTransaction(function () use ($connection, $channel, &$called): string {
      $this->manager->pop($channel, 'write');
      $connection->exec('INSERT INTO evidence VALUES(1)');
      $this->database->afterCommit(static function (): void { throw new RuntimeException('callback test'); });
      $this->database->afterCommit(function () use ($connection, &$called): void {
        $called = $connection->inTransaction();
      });
      return 'committed';
    });
    self::assertSame('committed', $result);
    self::assertFalse($called);
    self::assertSame(1, (int)$connection->query('SELECT COUNT(*) FROM evidence')->fetchColumn());
  }

  /**
   * 无事务时立即执行
   *
   * 相同异常隔离规则避免无事务路径因通知故障伪装业务失败。
   *
   * @return void 已执行且异常不传播
   */
  public function testWithoutTransactionRunsImmediatelyAndSafely(): void
  {
    $called = false;
    Db::afterCommit(function () use (&$called): void { $called = true; });
    self::assertTrue($called);
    $this->database->afterCommit(static function (): void { throw new RuntimeException('immediate test'); });
    self::assertSame(0, $this->database->transactionLevel());
  }

  /**
   * 提交异常时不执行回调
   *
   * 驱动可能已经提交但回执丢失，框架不能猜测成功后发送通知。
   *
   * @return void 原始异常保留，回调不会泄漏至下一事务
   */
  public function testUnknownCommitDiscardsCallbacks(): void
  {
    $connection = new CommitResponseLostPdo();
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    $called = false;
    try {
      $this->database->startTransaction(function () use ($channel, &$called): void {
        $this->manager->pop($channel, 'write');
        $this->database->afterCommit(function () use (&$called): void { $called = true; });
      });
      self::fail('提交异常必须传播');
    } catch (RuntimeException $error) {
      self::assertSame('commit response lost', $error->getMessage());
    }
    self::assertFalse($called);
    $this->database->startTransaction(fn (): null => null);
    self::assertFalse($called);
  }

  /**
   * 回调可开启自己的事务
   *
   * 执行前须分离旧回调集合，避免递归提交再次执行上一轮回调。
   *
   * @return void 两轮回调各执行一次
   */
  public function testCallbackCanStartNewTransaction(): void
  {
    $events = [];
    $this->database->startTransaction(function () use (&$events): void {
      $this->database->afterCommit(function () use (&$events): void {
        $events[] = 'first';
        $this->database->startTransaction(function () use (&$events): void {
          $this->database->afterCommit(function () use (&$events): void { $events[] = 'second'; });
        });
      });
    });
    self::assertSame(['first', 'second'], $events);
  }

  /**
   * 中间层回滚删除已提交孙层回调
   *
   * 孙层回调合并到中间层后仍受中间保存点约束。
   *
   * @return void 三层回调归属正确
   */
  public function testMiddleRollbackDiscardsMergedGrandchild(): void
  {
    $events = [];
    $this->database->startTransaction();
    $this->database->afterCommit(function () use (&$events): void { $events[] = 'outer'; });
    $this->database->startTransaction();
    $this->database->startTransaction();
    $this->database->afterCommit(function () use (&$events): void { $events[] = 'grandchild'; });
    $this->database->commit();
    $this->database->rollBack();
    $this->database->commit();
    self::assertSame(['outer'], $events);
  }

  /**
   * 拒绝向失效事务登记回调
   *
   * 服务端撤销事务使保存点失效时，登记不得绕过 rollback-only 守卫。
   *
   * @return void 失效回调拒绝登记，旧回调丢弃
   */
  public function testInvalidSavepointCannotRegisterCallbacks(): void
  {
    $connection = new PDO('sqlite::memory:');
    $channel = $this->createStub(Channel::class);
    $channel->method('pop')->willReturn($connection);
    $called = false;
    $this->database->startTransaction();
    $this->manager->pop($channel, 'write');
    $this->database->afterCommit(function () use (&$called): void { $called = true; });
    $this->database->startTransaction();
    $connection->rollBack();
    try {
      $this->database->commit();
      self::fail('保存点失效必须抛出异常');
    } catch (\PDOException) {
      self::assertSame(1, $this->database->transactionLevel());
    }
    try {
      $this->database->afterCommit(static function (): void {});
      self::fail('失效事务必须拒绝登记回调');
    } catch (DbException) {
      $this->database->rollBack();
    }
    self::assertFalse($called);
  }

  /**
   * 跨通道部分提交不执行回调
   *
   * 第一通道已提交不能代表整体成功，第二通道异常沿原路径传播。
   *
   * @return void 部分数据已落库但成功回调未执行
   */
  public function testPartialCommitDiscardsCallbacks(): void
  {
    $first = new PDO('sqlite::memory:');
    $first->exec('CREATE TABLE evidence(id INTEGER PRIMARY KEY)');
    $called = false;
    $this->database->startTransaction();
    foreach ([$first, new CommitResponseLostPdo()] as $connection) {
      $channel = $this->createStub(Channel::class);
      $channel->method('pop')->willReturn($connection);
      $this->manager->pop($channel, 'write');
    }
    $first->exec('INSERT INTO evidence VALUES(1)');
    $this->database->afterCommit(function () use (&$called): void { $called = true; });
    try {
      $this->database->commit();
      self::fail('第二通道异常必须传播');
    } catch (RuntimeException $error) {
      self::assertSame('commit response lost', $error->getMessage());
    }
    self::assertFalse($called);
    self::assertSame(1, (int)$first->query('SELECT COUNT(*) FROM evidence')->fetchColumn());
  }

  /**
   * 两个协程分别提交和回滚
   *
   * 暂停第一个事务后让第二个事务完整回滚，确认回调集合未共享。
   *
   * @return void 只执行提交方回调
   */
  public function testConcurrentCoroutinesKeepIndependentCallbacks(): void
  {
    $events = [];
    run(function () use (&$events): void {
      $gate = new CoroutineChannel(1);
      Coroutine::create(function () use ($gate, &$events): void {
        $manager = ConnectManager::factory();
        $manager->start();
        $manager->afterCommit(function () use (&$events): void { $events[] = 'committed'; });
        $gate->pop();
        $manager->commit();
      });
      $manager = ConnectManager::factory();
      $manager->start();
      $manager->afterCommit(function () use (&$events): void { $events[] = 'rolled back'; });
      $manager->rollBack();
      $gate->push(true);
    });
    self::assertSame(['committed'], $events);
  }

}


/**
 * 模拟数据库已提交但客户端丢失成功回执
 */
final class CommitResponseLostPdo extends PDO
{
  /**
   * 建立真实内存数据库
   *
   * 只在提交回执出口注入故障，保留真实事务语义。
   *
   * @return void 数据库已建立
   */
  public function __construct()
  {
    parent::__construct('sqlite::memory:');
  }

  /**
   * 丢失已提交回执
   *
   * 服务端提交后异常模拟网络断开，不能由框架猜测成功。
   *
   * @return bool 不正常返回
   * @throws RuntimeException 模拟提交回执丢失
   */
  public function commit(): bool
  {
    parent::commit();
    throw new RuntimeException('commit response lost');
  }
}
