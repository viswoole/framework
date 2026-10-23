<?php

declare(strict_types=1);

/** 真实 MySQL XA 回归：独立随机表验证回滚、零行 CAS 与单连接池复用，无业务数据依赖。 */

namespace Viswoole\Tests\Database;

use PDO;
use PDO\Mysql;
use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\Transaction\XaJournal;
use function Swoole\Coroutine\run;

final class XaMysqlRollbackTest extends TestCase
{
  protected function setUp(): void
  {
    if (getenv('VISWOOLE_TEST_DB_HOST') === false) {
      self::markTestSkipped('真实 MySQL 回归需显式配置 VISWOOLE_TEST_DB_HOST/USER/PASSWORD/NAME');
    }
  }

  public function testRollbackReleasesLocksAndAllowsSameConnectionReuse(): void
  {
    run(function (): void {
      $channel = $this->createChannel();
      $table = 'xa_rollback_' . bin2hex(random_bytes(6));
      $connection = $channel->pop('write');
      $connection->exec("CREATE TABLE `$table` (id INT PRIMARY KEY, balance INT NOT NULL) ENGINE=InnoDB");
      try {
        $connection->exec("INSERT INTO `$table` VALUES (1, 100)");
        $connectionId = (int)$connection->query('SELECT CONNECTION_ID()')->fetchColumn();
        $channel->put($connection);
        $this->assertRolledBack($channel, $table);
        $this->assertZeroRowRollback($channel, $table);
        $connection = $channel->pop('write');
        self::assertSame($connectionId, (int)$connection->query('SELECT CONNECTION_ID()')->fetchColumn());
        self::assertTrue($connection->beginTransaction(), '同连接后续 BEGIN 必须成功');
        self::assertSame(100, (int)$connection->query("SELECT balance FROM `$table` WHERE id = 1")->fetchColumn());
        $connection->rollBack();
      } finally {
        $connection->exec("DROP TABLE IF EXISTS `$table`");
        $channel->put($connection);
      }
    });
  }

  public function testDiscardReplacesActiveConnectionAndRestoresPoolCapacity(): void
  {
    run(function (): void {
      $channel = $this->createChannel();
      $connection = $channel->pop('write');
      $previousId = (int)$connection->query('SELECT CONNECTION_ID()')->fetchColumn();
      $xid = 'vw' . bin2hex(random_bytes(8)) . '-b1';
      $connection->exec("XA START '$xid'");
      $channel->discard($connection);
      $replacement = $channel->pop('write');
      try {
        self::assertNotSame($previousId, (int)$replacement->query('SELECT CONNECTION_ID()')->fetchColumn());
        self::assertTrue($replacement->beginTransaction());
        $replacement->rollBack();
      } finally {
        $channel->put($replacement);
      }
    });
  }

  /**
   * 真实修改后回滚，并经独立连接证明行锁已释放。
   * @param PDOChannel $channel 容量为一的连接池通道
   * @param string $table 随机测试表名
   * @return void
   */
  private function assertRolledBack(PDOChannel $channel, string $table): void
  {
    $manager = new ConnectManager();
    $manager->startXa(new XaJournal($channel, 'unused_rollback_journal'));
    $connection = $manager->pop($channel, 'write');
    $connection->exec("UPDATE `$table` SET balance = 0 WHERE id = 1");
    $manager->put($channel, $connection);
    $manager->rollBack();
    $observer = $this->createChannel();
    $other = $observer->pop('write');
    try {
      $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
      self::assertSame(1, $other->exec("UPDATE `$table` SET balance = 100 WHERE id = 1"));
    } finally {
      $observer->put($other);
    }
  }

  /**
   * 复现重复审核零行 CAS 后抛出业务异常的回滚状态。
   * @param PDOChannel $channel 测试通道
   * @param string $table 随机测试表名
   * @return void
   */
  private function assertZeroRowRollback(PDOChannel $channel, string $table): void
  {
    $manager = new ConnectManager();
    $manager->startXa(new XaJournal($channel, 'unused_rollback_journal'));
    $connection = $manager->pop($channel, 'write');
    self::assertSame(0, $connection->exec("UPDATE `$table` SET balance = 0 WHERE id = 1 AND balance = -1"));
    $manager->put($channel, $connection);
    $manager->rollBack();
  }

  /**
   * 显式测试配置建连；默认套件不依赖 MySQL，也不读取业务 .env。
   * @return PDOChannel 独立、容量为一的测试池
   */
  private function createChannel(): PDOChannel
  {
    return new PDOChannel(
      host: getenv('VISWOOLE_TEST_DB_HOST'),
      port: (int)(getenv('VISWOOLE_TEST_DB_PORT') ?: 3306),
      database: getenv('VISWOOLE_TEST_DB_NAME') ?: 'test',
      username: getenv('VISWOOLE_TEST_DB_USER') ?: 'root',
      password: getenv('VISWOOLE_TEST_DB_PASSWORD') ?: '',
      options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, Mysql::ATTR_FOUND_ROWS => true],
      pool_max_size: 1,
      pool_fill_size: 0,
    );
  }
}
