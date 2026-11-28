<?php

declare(strict_types=1);

/* 职责：以真实 Redis 验证持续入队与并发移除时老任务仍能取得领取机会，不替换生产扫描算法。 */

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use QueueTestRedis;
use Viswoole\Cache\MonitoredDriver;
use Viswoole\Core\Server\Task\TaskQueue;

require_once __DIR__ . '/fixtures/bootstrap.php';

/**
 * 到期任务扫描的持续进展回归
 */
final class RecoveryFairnessTest extends TestCase
{
  /**
   * @var QueueTestRedis 每用例独立 Redis 命名空间
   */
  private QueueTestRedis $store;
  /**
   * @var TaskQueue 生产队列状态机
   */
  private TaskQueue $queue;
  /**
   * @var string 测试键前缀
   */
  private string $prefix;

  /**
   * 创建独立测试队列
   *
   * 连接由运行器指定的隔离 Redis，不清空共享数据库。
   *
   * @return void 测试队列已准备
   */
  protected function setUp(): void
  {
    $this->prefix = 'recovery-fairness:' . bin2hex(random_bytes(8)) . ':';
    $this->store = new QueueTestRedis($this->prefix);
    $this->queue = new TaskQueue($this->store);
  }

  /**
   * 清理本用例记录
   *
   * 随机前缀覆盖任务、查询索引及节点容量索引，不影响其他用例。
   *
   * @return void 本用例数据已移除
   */
  protected function tearDown(): void
  {
    $keys = $this->store->connect()->keys($this->prefix . '*');
    if ($keys !== []) { $this->store->connect()->del($keys); }
    $this->store->close();
  }

  /**
   * 持续入队不能饿死早先就绪任务
   *
   * 每轮成功领取后有两条新任务到达，即使到期集合永远不为空也必须推进老任务。
   *
   * @return void 第二条任务在有界轮次内得到执行
   */
  public function testContinuousArrivalsDoNotStarveOldTask(): void
  {
    $this->enqueue();
    $old = $this->enqueue();
    for ($index = 0; $index < 100; $index++) { $this->enqueue(); }
    $consumed = [];
    for ($round = 0; $round < 100; $round++) {
      $this->queue->recover(function (array $delivery) use (&$consumed): int {
        $consumed[] = $delivery['queueId'];
        return $this->finish($delivery);
      }, ['instanceId' => 'consumer', 'capacity' => 1, 'topics' => ['known']]);
      $this->enqueue();
      $this->enqueue();
    }
    self::assertContains($old, $consumed, '成功移除成员后不能继续用旧排名跳过老任务');
  }

  /**
   * 并发移除加持续未知主题不能饿死已知主题
   *
   * 其他节点移除扫描前缀后，老任务移到本机偏移之前；新到达的未知主题不能无限延长这次扫描。
   *
   * @return void 扫描在有限窗口结束并重新覆盖旧任务
   */
  public function testConcurrentRemovalAndUnknownArrivalsStillMakeProgress(): void
  {
    $unknown = [];
    for ($index = 0; $index < 200; $index++) { $unknown[] = $this->enqueue('unknown'); }
    $old = $this->enqueue();
    $target = ['instanceId' => 'consumer', 'capacity' => 1, 'topics' => ['known']];
    $this->queue->recover($this->finish(...), $target);
    foreach (array_slice($unknown, 0, 150) as $id) {
      $this->queue->dispatch($id, $this->finish(...), ['instanceId' => 'consumer', 'capacity' => 1]);
    }
    $consumed = [];
    for ($round = 0; $round < 10; $round++) {
      for ($index = 0; $index < 200; $index++) { $this->enqueue('unknown'); }
      $this->queue->recover(function (array $delivery) use (&$consumed): int {
        $consumed[] = $delivery['queueId'];
        return $this->finish($delivery);
      }, $target);
    }
    self::assertContains($old, $consumed, '未知主题的持续到达不能阻止扫描回绕');
  }

  /**
   * 固定扫描上界排除后到任务
   *
   * 当前窗口只读取建立时已到期的成员，普通调用仍能发现随后到达的记录。
   *
   * @return void 窗口与默认到期语义独立
   */
  public function testDueWindowExcludesLaterArrivals(): void
  {
    $old = $this->enqueue();
    $cutoff = $this->store->now();
    usleep(2000);
    $new = $this->enqueue();
    self::assertSame([$old], $this->store->getDueQueue(100, 0, $cutoff));
    self::assertSame([$old], new MonitoredDriver($this->store, 'fairness')->getDueQueue(100, 0, $cutoff));
    self::assertContains($new, $this->store->getDueQueue());
    self::assertSame([], $this->store->getDueQueue(100, 1, $cutoff));
  }

  /**
   * 未来窗口不能提前领取任务
   *
   * 即使调用方传入未来上界，存储查询也必须截断到 Redis 当前时间。
   *
   * @return void 尚有投递租约的任务未被到期查询返回
   */
  public function testFutureWindowDoesNotReturnUndueTask(): void
  {
    $id = $this->queue->enqueue(['topic' => 'known', 'data' => [], 'dispatch_time' => microtime(true), 'expire' => null], 'producer:0');
    self::assertNotContains($id, $this->store->getDueQueue(100, 0, $this->store->now() + 100));
  }

  /**
   * 拒绝非有限时间上界
   *
   * 非法上界在提交 Redis 前拒绝，避免 Lua 解析产生不可重试的协议错误。
   *
   * @return void 参数错误明确抛出
   */
  public function testRejectsInvalidWindow(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->store->getDueQueue(100, 0, INF);
  }

  /**
   * 登记一个共享就绪任务
   *
   * 生产受理与 Redis 时钟保持真实实现。
   *
   * @param string $topic 任务主题
   * @return string 稳定队列身份
   */
  private function enqueue(string $topic = 'known'): string
  {
    return $this->queue->enqueue(['topic' => $topic, 'data' => [], 'dispatch_time' => microtime(true), 'expire' => null], 'producer:0', true);
  }

  /**
   * 同步模拟本机 Task Worker 完成
   *
   * 仍经过真实消费令牌及 ACK，保证成功领取会移动到期集合成员。
   *
   * @param array $delivery 真实投递信封
   * @return int 原生投递成功信号
   */
  private function finish(array $delivery): int
  {
    $lease = $this->queue->consume($delivery, 'consumer', 1);
    self::assertNotNull($lease);
    self::assertTrue($this->queue->ack($lease));
    return 0;
  }
}
