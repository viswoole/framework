<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Throwable;
use function Swoole\Coroutine\run;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Channel\PDO\PDOPool;

/**
 * 批次 3（连接管理层）加固回归测试
 *
 * 覆盖两项中危修复：
 *  1. B3-02 非缓冲查询选项（MYSQL_ATTR_USE_BUFFERED_QUERY = false）与
 *     "execute 先归还连接、调用方后 fetch"的连接池契约不兼容，应在建连时
 *     fail-fast 拒绝，而非等到运行时出现 Commands out of sync 协议错乱
 *  2. B3-01 读写分离的粘滞/借出池索引存于协程上下文，键必须按通道隔离——
 *     否则同一协程内先后使用多个读写分离通道时，通道 B 的读会被通道 A
 *     记录的粘滞索引误路由到写池（池数不一致时甚至越界崩溃）
 */
class ChannelHardeningTest extends TestCase
{
  /**
   * 配置非缓冲查询的 MySQL 通道在建连时应被拒绝
   */
  public function testUnbufferedQueryOptionRejected(): void
  {
    $channel = new PDOChannel(
      type: DriverType::MYSQL,
      // 与 PDOPool::assertBufferedQuery 一致使用驱动常量（PHP 8.5 起 PDO:: 类常量弃用）
      options: [PDO\Mysql::ATTR_USE_BUFFERED_QUERY => false]
    );
    // Coroutine\run 不会向外重抛协程内异常，需在协程内捕获后传出断言
    $thrown = null;
    run(function () use ($channel, &$thrown) {
      try {
        $channel->pop('read');
      } catch (InvalidArgumentException $e) {
        $thrown = $e;
      }
    });
    self::assertNotNull($thrown, '配置非缓冲查询时应抛出 InvalidArgumentException');
    self::assertInstanceOf(InvalidArgumentException::class, $thrown);
    self::assertStringContainsString('MYSQL_ATTR_USE_BUFFERED_QUERY', $thrown->getMessage());
  }

  /**
   * falsy 值（如 0）同样等效关闭缓冲，校验应宽松拒绝而非仅识别 === false
   */
  public function testUnbufferedQueryOptionFalsyRejected(): void
  {
    $channel = new PDOChannel(
      type: DriverType::MYSQL,
      options: [PDO\Mysql::ATTR_USE_BUFFERED_QUERY => 0]
    );
    $thrown = null;
    run(function () use ($channel, &$thrown) {
      try {
        $channel->pop('read');
      } catch (InvalidArgumentException $e) {
        $thrown = $e;
      }
    });
    self::assertNotNull($thrown, 'falsy 的非缓冲配置应同样被拒绝');
    self::assertInstanceOf(InvalidArgumentException::class, $thrown);
  }

  /**
   * 通道 B 的读操作不应被通道 A 记录的粘滞写池索引误路由
   *
   * 场景：A 配置 2 个写池、B 配置 1 个写池，二者均开启粘性读。
   * 协程 1 写 A（轮询到写池 0）；主协程再写 A（轮询到写池 1 并记录粘滞索引 1）；
   * 随后 B 读——若粘滞索引跨通道共享，B 会以写池身份借出连接（误路由）。
   */
  public function testStickyIndexNotSharedAcrossChannels(): void
  {
    $tmpDb = sys_get_temp_dir() . '/viswoole_h3_' . uniqid() . '.sqlite';
    $pdo = new PDO('sqlite:' . $tmpDb);
    $pdo->exec('CREATE TABLE h3t (id INTEGER)');
    try {
      $channelA = new PDOChannel(
        type: DriverType::SQLite,
        host: ['read' => ['r'], 'write' => ['w1', 'w2']],
        database: $tmpDb
      );
      $channelB = new PDOChannel(
        type: DriverType::SQLite,
        host: ['read' => ['r'], 'write' => ['w1']],
        database: $tmpDb
      );

      run(function () use ($channelA, $channelB, &$error) {
        try {
          // 协程 1：通道 A 首次写，推进 A 的写池轮询计数器（命中写池 0）
          $wg = new WaitGroup();
          $wg->add();
          Coroutine::create(function () use ($channelA, $wg, &$error) {
            try {
              $channelA->execute('CREATE TABLE IF NOT EXISTS h3t (id INTEGER)');
            } catch (Throwable $e) {
              $error = $e;
            } finally {
              $wg->done();
            }
          });
          $wg->wait();
          if ($error !== null) return;

          // 主协程：A 再次写（全新协程上下文）→ 轮询到写池 1，记录粘滞索引 1
          $channelA->execute('INSERT INTO h3t VALUES (1)');

          // 通道 B 读：必须由 B 自己的读池服务，而非 A 的粘滞写池索引
          $channelB->execute('SELECT COUNT(*) AS c FROM h3t');

          $poolProp = new ReflectionProperty(PDOChannel::class, 'pool');
          /** @var array{read:PDOPool[],write:PDOPool[]} $poolsB */
          $poolsB = $poolProp->getValue($channelB);
          self::assertSame(
            1,
            $poolsB['read'][0]->length(),
            'B 的读应由 B 的读池服务（池内应有归还的空闲连接）'
          );
          self::assertSame(
            0,
            $poolsB['write'][0]->length(),
            'B 的写池不应被读操作误借用'
          );
        } catch (Throwable $e) {
          $error = $e;
        }
      });
      self::assertNull($error, 'B 的读操作不应被 A 的粘滞索引误路由：' . ($error?->getMessage() ?? ''));
    } finally {
      if (is_file($tmpDb)) unlink($tmpDb);
    }
  }
}
