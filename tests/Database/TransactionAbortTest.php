<?php

declare(strict_types=1);

/* 职责：以真实SQLite连接复现服务端撤销事务后的异常保留、失效防护及连接复用。 */

namespace Viswoole\Tests\Database;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\Channel;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;
use Viswoole\Database\Exception\DbException;

/**
 * 框架事务失效契约
 *
 * SQLite撤销真实事务模拟InnoDB自动回滚后的保存点丢失，不依赖业务服务或外部数据库。
 */
final class TransactionAbortTest extends TestCase
{
  private ConnectManager $manager;
  private DbManager $database;
  private Channel $channel;
  private PDO $connection;
  private mixed $previousManager;

  /**
   * 创建独立事务管理器与真实内存库
   *
   * 不调用DbManager构造器，隔离与本次事务方法无关的应用配置及日志装配。
   *
   * @return void 当前上下文已切至独立管理器
   */
  protected function setUp(): void
  {
    $this->previousManager = Context::get('$_db_transaction');
    $this->manager = new ConnectManager();
    Context::set('$_db_transaction', $this->manager);
    $this->database = (new ReflectionClass(DbManager::class))->newInstanceWithoutConstructor();
    $this->connection = new PDO('sqlite::memory:');
    $this->connection->exec('CREATE TABLE evidence (id INTEGER PRIMARY KEY)');
    $this->channel = $this->createStub(Channel::class);
    $this->channel->method('pop')->willReturn($this->connection);
  }

  /**
   * 恢复调用方上下文
   *
   * 测试失败时也结束残留真实事务，不能污染后续用例。
   *
   * @return void 测试上下文已恢复
   */
  protected function tearDown(): void
  {
    if ($this->connection->inTransaction()) {
      $this->connection->rollBack();
    }
    Context::set('$_db_transaction', $this->previousManager);
  }

  /**
   * 保留原始驱动异常并复用清洁连接
   *
   * 自动回滚后保存点消失，清理失败不能覆盖原始错误对象或SQLSTATE。
   *
   * @return void 原异常、层级及下一次提交均正确
   */
  public function testServerRollbackPreservesOriginalAndConnectionIsReusable(): void
  {
    $original = new PDOException('deadlock original', 40001);
    try {
      $this->database->startTransaction(function () use ($original): void {
        $this->openConnection();
        $this->database->startTransaction(function () use ($original): void {
          $this->connection->rollBack();
          throw $original;
        });
      });
      self::fail('失效事务应抛出原始错误');
    } catch (Throwable $error) {
      self::assertSame($original, $error);
    }
    self::assertSame(0, $this->manager->transactionLevel());
    $this->database->startTransaction(function (): void {
      $this->openConnection();
      $this->connection->exec('INSERT INTO evidence VALUES (1)');
    });
    self::assertSame(1, (int)$this->connection->query('SELECT COUNT(*) FROM evidence')->fetchColumn());
  }

  /**
   * 拒绝继续使用失效外层事务
   *
   * 调用方捕获内层异常也不能让后续SQL落入自动提交或让外层假成功。
   *
   * @return void 查询、嵌套开启及提交均被拒绝
   */
  public function testCaughtInnerAbortCannotResumeOrCommit(): void
  {
    $this->expectException(DbException::class);
    try {
      $this->database->startTransaction(function (): void {
        $this->openConnection();
        try {
          $this->database->startTransaction(function (): void {
            $this->connection->rollBack();
            throw new RuntimeException('original');
          });
        } catch (RuntimeException $error) {
          self::assertSame('original', $error->getMessage());
        }
        $this->assertBlocked(fn (): mixed => $this->manager->pop($this->channel, 'write'));
        $this->assertBlocked(fn (): mixed => $this->manager->start());
      });
    } finally {
      self::assertSame(0, $this->manager->transactionLevel());
      self::assertFalse($this->connection->inTransaction());
    }
  }

  /**
   * 深层失效逐层退出不再引用消失的保存点
   *
   * 第三层失败后第二层仍保持仅可回滚，全部退出后才能重新开始。
   *
   * @return void 原始异常和每层状态均正确
   */
  public function testDeepAbortUnwindsWithoutReusingMissingSavepoints(): void
  {
    $original = new RuntimeException('deep original');
    try {
      $this->database->startTransaction(function () use ($original): void {
        $this->openConnection();
        $this->database->startTransaction(function () use ($original): void {
          try {
            $this->database->startTransaction(function () use ($original): void {
              $this->connection->rollBack();
              throw $original;
            });
          } catch (RuntimeException $error) {
            self::assertSame($original, $error);
            self::assertSame(2, $this->manager->transactionLevel());
            $this->assertBlocked(fn (): mixed => $this->manager->commit());
            throw $error;
          }
        });
      });
      self::fail('深层失效必须传播');
    } catch (Throwable $error) {
      self::assertSame($original, $error);
    }
    self::assertSame(0, $this->manager->transactionLevel());
  }

  /**
   * 内层提交失败不能误回滚父作用域
   *
   * 提交已退出内层时，闭包清理不得再退出父层；父层继续保持仅可回滚状态。
   *
   * @return void 父层不会变成自动提交或返回假成功
   */
  public function testFailedInnerCommitLeavesParentRollbackOnly(): void
  {
    $this->expectException(DbException::class);
    try {
      $this->database->startTransaction(function (): void {
        $this->openConnection();
        $this->connection->exec('INSERT INTO evidence VALUES (1)');
        try {
          $this->database->startTransaction(function (): void {
            $this->connection->exec('RELEASE SAVEPOINT viswoole_sp_2');
          });
          self::fail('丢失保存点的内层提交必须失败');
        } catch (PDOException $error) {
          self::assertStringContainsString('savepoint', $error->getMessage());
        }
        self::assertSame(1, $this->manager->transactionLevel());
        $this->assertBlocked(fn (): mixed => $this->manager->pop($this->channel, 'write'));
      });
    } finally {
      self::assertSame(0, $this->manager->transactionLevel());
      self::assertSame(0, (int)$this->connection->query('SELECT COUNT(*) FROM evidence')->fetchColumn());
    }
  }

  /**
   * 保留普通保存点回滚语义
   *
   * 普通业务失败只撤销内层，外层仍可捕获后提交已有写入。
   *
   * @return void 仅内层记录被撤销
   */
  public function testOrdinaryInnerFailureKeepsOuterUsable(): void
  {
    $this->database->startTransaction(function (): void {
      $this->openConnection();
      $this->connection->exec('INSERT INTO evidence VALUES (1)');
      try {
        $this->database->startTransaction(function (): void {
          $this->connection->exec('INSERT INTO evidence VALUES (2)');
          throw new RuntimeException('inner');
        });
      } catch (RuntimeException $error) {
        self::assertSame('inner', $error->getMessage());
      }
      self::assertSame(1, $this->manager->transactionLevel());
      $this->connection->exec('INSERT INTO evidence VALUES (3)');
    });
    self::assertSame([1, 3], $this->connection->query('SELECT id FROM evidence ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
  }

  /**
   * 断言失效事务操作被拒绝
   *
   * 失败断言留在catch外，避免把PHPUnit断言异常误判成预期异常。
   *
   * @param callable $operation 待拒绝的框架操作
   * @return void 已收到明确事务失效错误
   */
  private function assertBlocked(callable $operation): void
  {
    try {
      $operation();
    } catch (DbException $error) {
      self::assertStringContainsString('事务', $error->getMessage());
      return;
    }
    self::fail('失效事务不能继续操作');
  }

  /**
   * 让连接加入当前真实事务
   *
   * 按框架归还标记避免后续请求另开同一PDO事务。
   *
   * @return void 连接已加入且可复用
   */
  private function openConnection(): void
  {
    $connection = $this->manager->pop($this->channel, 'write');
    $this->manager->put($this->channel, $connection);
  }
}
