<?php

declare(strict_types=1);

/* 职责：以真实 Redis 验证跨节点领取、容量上限及任务身份，不模拟原子状态脚本。 */

namespace Viswoole\Tests\TaskQueue;

use Closure;
use PHPUnit\Framework\TestCase;
use Redis;
use Viswoole\Cache\Driver\Redis as RedisDriver;
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 集群就绪任务及容量契约
 */
final class ClusterDispatchTest extends TestCase
{
  /**
   * @var ClusterDispatchStore 独立随机键空间
   */
  private ClusterDispatchStore $store;
  /**
   * @var TaskQueue 被测真实队列
   */
  private TaskQueue $queue;
  /**
   * @var string 当前测试唯一前缀
   */
  private string $prefix;

  /**
   * 创建隔离队列
   *
   * 只能连接专门提供的测试 Redis，不清空共享数据库。
   *
   * @return void 队列已就绪
   */
  protected function setUp(): void
  {
    $this->prefix = 'cluster-dispatch:' . bin2hex(random_bytes(8)) . ':';
    $this->store = new ClusterDispatchStore($this->prefix);
    $this->queue = new TaskQueue($this->store);
  }

  /**
   * 清理测试键空间
   *
   * 所有队列索引与容量键都使用同一随机前缀。
   *
   * @return void 本用例数据已清理
   */
  protected function tearDown(): void
  {
    $keys = $this->store->connect()->keys($this->prefix . '*');
    if ($keys !== []) { $this->store->connect()->del($keys); }
  }

  /**
   * 新任务等待共享领取
   *
   * 受理不预占生产节点租约，首次执行也必须先取得投递资格。
   *
   * @return void 任务立即可领取但不能绕过认领直接执行
   */
  public function testReadySubmissionDoesNotReserveProducer(): void
  {
    $id = $this->enqueue();
    self::assertContains($id, $this->store->getDueQueue());
    self::assertNull($this->queue->consume($this->queue->delivery($id), 'producer', 1));
  }

  /**
   * 未注册主题不能阻塞后续任务
   *
   * 每轮有界扫描必须继续向后推进，不能反复停在同一批无法领取的任务上。
   *
   * @return void 后续已注册主题最终得到执行资格
   */
  public function testUnknownTopicsDoNotStarveLaterTasks(): void
  {
    for ($index = 0; $index < 100; $index++) { $this->enqueue(); }
    $id = $this->queue->enqueue(['topic' => 'known', 'data' => [], 'dispatch_time' => microtime(true), 'expire' => null], 'producer:0', true);
    $deliveries = [];
    for ($round = 0; $round < 20 && $deliveries === []; $round++) {
      $this->queue->recover(
        static function (array $data) use (&$deliveries): int { $deliveries[] = $data['queueId'];
          return 0; },
        $this->target('a') + ['topics' => ['known']]
      );
    }
    self::assertSame([$id], $deliveries);
  }

  /**
   * 多节点按容量分担
   *
   * 投递及执行中的任务共同占用容量，ACK 后才能继续领取。
   *
   * @return void 节点容量和稳定身份正确
   */
  public function testNodesShareWorkWithoutExceedingCapacity(): void
  {
    $first = $this->enqueue();
    $second = $this->enqueue();
    $delivery = null;
    self::assertSame(0, $this->queue->dispatch($first, static function (array $data) use (&$delivery): int { $delivery = $data;
      return 0; }, $this->target('a')));
    self::assertFalse($this->queue->dispatch($second, static fn (): int => 0, $this->target('a')));
    self::assertSame(0, $this->queue->dispatch($second, static fn (): int => 0, $this->target('b')));
    self::assertSame(1, $this->store->getQueueAssignmentCount('a'));
    self::assertNull($this->queue->consume($delivery, 'b', 1));
    $lease = $this->queue->consume($delivery, 'a', 1);
    self::assertNotNull($lease);
    self::assertSame($first, $lease['queueId']);
    self::assertSame(1, $this->store->getQueueAssignmentCount('a'));
    self::assertTrue($this->queue->ack($lease));
    self::assertSame(0, $this->store->getQueueAssignmentCount('a'));
    self::assertSame(0, $this->queue->dispatch($this->enqueue(), static fn (): int => 0, $this->target('a')));
  }

  /**
   * 并发领取在 Lua 内复核容量
   *
   * 在第一个领取提交前让另一条任务抢先占满同节点容量，不能只依赖 PHP 预检查。
   *
   * @return void 第二个原子提交被拒绝
   */
  public function testCapacityIsCheckedInsideAtomicClaim(): void
  {
    $first = $this->enqueue();
    $second = $this->enqueue();
    $this->store->beforeUpdate = function () use ($second): void {
      self::assertSame(0, $this->queue->dispatch($second, static fn (): int => 0, $this->target('a')));
    };
    self::assertFalse($this->queue->dispatch($first, static fn (): int => 0, $this->target('a')));
    self::assertSame(1, $this->store->getQueueAssignmentCount('a'));
  }

  /**
   * 崩溃后的投递可跨节点接管
   *
   * 原领取身份过期后换节点保持逻辑 ID，旧投递信封不得执行。
   *
   * @return void 身份稳定且旧执行权失效
   */
  public function testExpiredAssignmentMovesWithoutChangingIdentity(): void
  {
    $id = $this->enqueue();
    $oldDelivery = null;
    $this->queue->dispatch($id, static function (array $data) use (&$oldDelivery): int { $oldDelivery = $data;
      return 0; }, $this->target('a'));
    $before = $this->store->getQueueMetadata($id);
    $after = $before;
    $after['leaseUntil'] = $after['retryAt'] = 0;
    self::assertTrue($this->store->updateQueue($id, ['expected' => $before, 'value' => $after]));
    self::assertSame(0, $this->queue->dispatch($id, static fn (): int => 0, $this->target('b')));
    self::assertNull($this->queue->consume($oldDelivery, 'a', 1));
    $lease = $this->queue->consume($this->queue->delivery($id), 'b', 2);
    self::assertSame($id, $lease['queueId']);
    self::assertSame('producer', $lease['producerInstanceId']);
    self::assertSame('b', $lease['executionInstanceId']);
    self::assertSame(0, $this->store->getQueueAssignmentCount('a'));
    self::assertTrue($this->queue->ack($lease));
  }

  /**
   * 失败释放容量，人工重投保留身份关联
   *
   * 新队列身份由框架原子重投产生，自动分配不改变原逻辑身份。
   *
   * @return void 容量释放且原 ID 可追踪
   */
  public function testFailureAndManualRetryKeepAssociation(): void
  {
    $id = $this->enqueue();
    $this->queue->dispatch($id, static fn (): int => 0, $this->target('a'));
    $lease = $this->queue->consume($this->queue->delivery($id), 'a', 1);
    self::assertTrue($this->queue->fail($lease, 'test', true));
    self::assertSame(0, $this->store->getQueueAssignmentCount('a'));
    $failed = new \Viswoole\Core\Server\Task\TaskFailedQueue($this->store);
    $newId = $failed->retry($id);
    self::assertIsString($newId);
    self::assertSame($id, $this->store->getQueueMetadata($newId)['originalQueueId']);
    self::assertSame(0, $this->queue->dispatch($newId, static fn (): int => 0, $this->target('b')));
  }

  /**
   * 登记待分配任务
   *
   * 使用稳定生产节点身份检查实际执行节点与生产节点无绑定。
   *
   * @return string 调度前队列身份
   */
  private function enqueue(): string
  {
    return $this->queue->enqueue(['topic' => 'demo', 'data' => [], 'dispatch_time' => microtime(true), 'expire' => null], 'producer:0', true);
  }

  /**
   * 创建节点领取上下文
   *
   * 容量由框架本机 Task Worker 配置计算，不进入业务载荷。
   *
   * @param string $instanceId 执行节点
   * @return array{instanceId:string,capacity:int} 本机领取参数
   */
  private function target(string $instanceId): array
  {
    return ['instanceId' => $instanceId, 'capacity' => 1];
  }
}

/**
 * 仅提供独立原生连接与受控交错，存储协议复用真实 Redis 驱动
 */
final class ClusterDispatchStore extends RedisDriver
{
  /**
   * @var Redis 测试独占连接
   */
  private Redis $connection;
  /**
   * @var Closure|null 单次状态交错入口
   */
  public ?Closure $beforeUpdate = null;

  /**
   * 建立独占命名空间
   *
   * 不使用应用默认 Redis，测试服务地址必须由运行器提供。
   *
   * @param string $prefix 随机键前缀
   * @return void 连接已就绪
   */
  public function __construct(string $prefix)
  {
    parent::__construct(prefix: $prefix);
    $this->connection = new Redis();
    $this->connection->connect(getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1', 6379, 2);
  }

  /**
   * 返回当前测试的独立连接
   *
   * 沿用生产脚本与序列化边界。
   *
   * @return Redis 原生测试连接
   */
  public function connect(): Redis
  {
    return $this->connection;
  }

  /**
   * 受控交错后调用生产原子操作
   *
   * 交错只发生一次，防止递归注入。
   *
   * @param string $key 队列身份
   * @param array $change 条件及状态变更
   * @return bool 原子提交结果
   */
  public function updateQueue(string $key, array $change): bool
  {
    $callback = $this->beforeUpdate;
    $this->beforeUpdate = null;
    if ($callback !== null) { $callback(); }
    return parent::updateQueue($key, $change);
  }
}
