<?php

declare(strict_types=1);

/** 表结构冷缓存回归：元数据读取必须复用事务连接，不能再向池借第二条连接。 */

namespace Viswoole\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\{DriverType, PDOChannel};
use Viswoole\Database\ConnectManager;
use Viswoole\Database\DbManager;

final class SqlBuilderTransactionMetadataTest extends TestCase
{
  /** @return void 单连接池中，事务首次非严格 INSERT 必须完成并支持回滚 */
  public function testColdMetadataUsesTransactionConnectionWithSingleConnectionPool(): void
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('需要 pdo_sqlite');
    }
    $db = App::factory()->make(DbManager::class);
    $db->setDebug(false);
    $path = sys_get_temp_dir() . '/metadata-transaction-' . bin2hex(random_bytes(8)) . '.sqlite';
    $failure = null;
    Coroutine\run(function () use ($db, $path, &$failure): void {
      $channel = new PDOChannel(type: DriverType::SQLite, database: $path, pool_max_size: 1, pool_fill_size: 0, pool_timeout_time: 1);
      try {
        $db->startTransaction(function () use ($channel): void {
          $channel->execute('CREATE TABLE metadata_transaction_test (id INTEGER PRIMARY KEY, value TEXT)');
          $channel->table('metadata_transaction_test')->strict(false)->insert(['id' => 1, 'value' => 'first', 'ignored' => 'filtered']);
          self::assertSame(1, (int)$channel->table('metadata_transaction_test')->count());
          self::assertSame(1, ConnectManager::factory()->transactionLevel());
          throw new RuntimeException('expected rollback');
        });
      } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'expected rollback') $failure = $error;
      } catch (\Throwable $error) {
        $failure = $error;
      }
    });
    if (is_file($path)) unlink($path);
    if ($failure !== null) throw $failure;
  }
}
