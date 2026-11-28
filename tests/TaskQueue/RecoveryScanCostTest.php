<?php

declare(strict_types=1);

/* 职责：验证稳定未知主题前缀不能在每次成功领取后反复放大扫描成本，不以墙钟耗时断言性能。 */

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use Redis;
use Viswoole\Cache\Driver\Redis as RedisDriver;
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 未知主题绕行的有界读取回归
 */
final class RecoveryScanCostTest extends TestCase
{
  /**
   * 成功领取不能反复扫描整个未知主题前缀
   *
   * 使用真实 Redis 和生产 ACK 移除已领取任务，只观测元数据读取数量，排除机器负载对耗时断言的干扰。
   *
   * @return void 正常任务完成且元数据读取没有按前缀长度乘任务数放大
   */
  public function testUnknownPrefixDoesNotMultiplyReadsForEveryTask(): void
  {
    $prefix = 'recovery-scan-cost:' . bin2hex(random_bytes(8)) . ':';
    $store = new RecoveryScanProbe($prefix);
    $queue = new TaskQueue($store);
    $enqueue = static fn (string $topic): string => $queue->enqueue(['topic' => $topic, 'data' => [], 'dispatch_time' => microtime(true), 'expire' => null], 'producer:0', true);
    try {
      for ($index = 0; $index < 100; $index++) { $enqueue('unknown'); }
      for ($index = 0; $index < 200; $index++) { $enqueue('known'); }
      $completed = 0;
      for ($round = 0; $round < 1000 && $completed < 200; $round++) {
        $queue->recover(static function (array $delivery) use ($queue, &$completed): int {
          $lease = $queue->consume($delivery, 'consumer', 1);
          self::assertNotNull($lease);
          self::assertTrue($queue->ack($lease));
          $completed++;
          return 0;
        }, ['instanceId' => 'consumer', 'capacity' => 1, 'topics' => ['known']]);
      }
      self::assertSame(200, $completed);
      // 预留多次公平性回绕与消费复核的空间，但不能为200条任务完整重读100条前缀。
      self::assertLessThan(6000, $store->metadataReads, '固定未知主题前缀不应在每次成功投递后从头重读');
    } finally {
      $keys = $store->connect()->keys($prefix . '*');
      if ($keys !== []) { $store->connect()->del($keys); }
      $store->connect()->close();
    }
  }
}

/**
 * 仅计数元数据读取，其余命令与原子状态变更全部使用真实 Redis 实现
 */
final class RecoveryScanProbe extends RedisDriver
{
  /**
   * @var int 生产扫描与消费实际发起的元数据读取次数
   */
  public int $metadataReads = 0;
  /**
   * @var Redis 当前用例独占连接
   */
  private Redis $connection;

  /**
   * 建立隔离读取探针
   *
   * 连接地址由运行器提供，前缀仅属于本用例。
   *
   * @param string $prefix 测试独占命名空间
   * @return void 驱动及连接已准备
   */
  public function __construct(string $prefix)
  {
    parent::__construct(prefix: $prefix);
    $this->connection = new Redis();
    $this->connection->connect(getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1', 6379, 2);
  }

  /**
   * 使用本用例原生连接
   *
   * 不替换父驱动的 Lua 或查询实现。
   *
   * @return Redis 原生连接
   */
  public function connect(): Redis
  {
    return $this->connection;
  }

  /**
   * 计数并执行真实元数据读取
   *
   * 计数不修改载荷、时钟和返回记录。
   *
   * @param string $key 当前队列身份
   * @return array|null 生产驱动元数据
   * @throws \Throwable 存储查询失败
   */
  public function getQueueMetadata(string $key): ?array
  {
    $this->metadataReads++;
    return parent::getQueueMetadata($key);
  }
}
