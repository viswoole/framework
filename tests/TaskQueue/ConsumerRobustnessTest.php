<?php
/**
 * 职责：验证消费入口的存储故障兜底与存储时钟域过期判定；不依赖真实 Redis。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use RedisException;
use RuntimeException;
use Swoole\Server as SwooleServer;
use Swoole\Server\Task as SwooleTask;
use Viswoole\Cache\Contract\QueueStoreInterface;
use Viswoole\Core\Server\Task\TaskManager;
use Viswoole\Core\Server\Task\TaskQueue;
use Viswoole\Core\Server\Task\TaskQueueConsumer;

/**
 * 暴露 onTask 入口并绕过服务事件注册，供存储时钟域判定测试使用。
 */
final class ExposedTaskManager extends TaskManager
{
  /**
   * 绕过父类构造，避免向 ServerEventHook 注册全局事件。
   * @param TaskQueue $queue 预先构建的队列边界
   * @param array<string,callable> $topics 已注册主题
   */
  public function __construct(TaskQueue $queue, array $topics)
  {
    $this->taskQueue = $queue;
    $this->topics = $topics;
    $this->instanceId = 'test-instance';
  }

  /**
   * 公开投递入口，透传 onTask 返回值。
   * @param SwooleServer $server 服务实例
   * @param SwooleTask $task 原始投递
   * @return mixed 处理器结果或失败信号
   */
  public function dispatchTask(SwooleServer $server, SwooleTask $task): mixed
  {
    return $this->onTask($server, $task);
  }
}

/**
 * 消费入口健壮性回归测试。
 */
final class ConsumerRobustnessTest extends TestCase
{
  /**
   * @var string|null 兜底日志重定向文件，避免 error_log 污染测试输出
   */
  private ?string $logFile = null;
  /**
   * @var SwooleServer|null 共享服务模拟，Swoole 每进程只允许创建一个 Server 实例
   */
  private static ?SwooleServer $server = null;

  /**
   * 将兜底日志输出重定向到临时文件。
   * @return void
   */
  protected function setUp(): void
  {
    $this->logFile = tempnam(sys_get_temp_dir(), 'task-consumer-log-');
    ini_set('error_log', $this->logFile);
  }

  /**
   * 回收日志重定向。
   * @return void
   */
  protected function tearDown(): void
  {
    ini_set('error_log', 'syslog');
    if ($this->logFile !== null && is_file($this->logFile)) { unlink($this->logFile); }
    $this->logFile = null;
  }

  /**
   * 构建支持队列能力且状态由闭包维护的存储模拟。
   * @param callable $metadata 元数据读取回调
   * @param callable $update 状态提交回调
   * @return QueueStoreInterface 存储模拟
   */
  private function mockStore(callable $metadata, callable $update): QueueStoreInterface
  {
    $store = $this->createMock(QueueStoreInterface::class);
    $store->method('supportsQueueOperations')->willReturn(true);
    $store->method('supportsAtomicOperations')->willReturn(true);
    $store->method('getQueueMetadata')->willReturnCallback($metadata);
    $store->method('updateQueue')->willReturnCallback($update);
    return $store;
  }

  /**
   * 构造带 shadow worker_id 的服务实例，避免启动真实服务；
   * Swoole 每进程仅允许一个 Server 实例，故跨用例共享。
   * @return SwooleServer 服务模拟
   */
  private function mockServer(): SwooleServer
  {
    if (self::$server === null) {
      self::$server = new class('127.0.0.1', 0) extends SwooleServer {
        public $worker_id = 3;
      };
    }
    return self::$server;
  }

  /**
   * 消费认领阶段的存储故障必须返回失败信号而不是抛出；
   * 任务保留投递租约，由恢复扫描按 at-least-once 语义接管。
   */
  public function testConsumeStorageFailureReturnsFalseInsteadOfThrowing(): void
  {
    $store = $this->mockStore(
      static fn (): array => throw new RedisException('模拟连接中断'),
      static fn (): bool => true
    );
    $consumer = new TaskQueueConsumer(new TaskQueue($store), 'node-1');
    $task = new SwooleTask();
    $task->data = ['topic' => 'demo', 'queueId' => 'q-1', 'queueToken' => 'tok'];
    $result = $consumer->execute($task, $this->mockServer(), [
      'validate' => static function (): void {},
      'handle' => static fn (): string => 'ran',
    ]);
    self::assertFalse($result);
  }

  /**
   * 消费校验失败后的终态提交存储故障必须被兜底，不能让异常穿透消费入口。
   */
  public function testValidateFailureStorageErrorReturnsFalseInsteadOfThrowing(): void
  {
    $state = ['queueId' => 'q-1', 'queueToken' => 'issued', 'topic' => 'demo', 'queueGroup' => 'test:0',
      'queueState' => 'dispatched', 'leaseUntil' => 1010.0, 'retryAt' => 0, 'attempts' => 0];
    $store = $this->mockStore(
      static function () use (&$state): array { return $state; },
      // 认领提交（expected 为 dispatched）成功；后续执行权提交（fail）注入存储故障
      static function (string $id, array $change) use (&$state): bool {
        if (($change['expected']['queueState'] ?? null) !== 'dispatched') {
          throw new RedisException('模拟失败状态提交中断');
        }
        $state = $change['value'];
        return true;
      }
    );
    $consumer = new TaskQueueConsumer(new TaskQueue($store), 'node-1');
    $task = new SwooleTask();
    $task->data = $state;
    $result = $consumer->execute($task, $this->mockServer(), [
      'validate' => static function (): void { throw new RuntimeException('主题校验未通过'); },
      'handle' => static fn (): string => 'ran',
    ]);
    self::assertFalse($result);
  }

  /**
   * 队列消费边界的有效期判定必须使用存储端时钟：
   * 存储时钟认为已过期而应用时钟认为未过期时，任务必须直接进入失败队列。
   */
  public function testExpiryUsesStoreClock(): void
  {
    $state = ['queueId' => 'q-1', 'queueToken' => 'issued', 'topic' => 'demo', 'queueGroup' => 'test:0',
      'queueState' => 'dispatched', 'leaseUntil' => 2000003100.0, 'retryAt' => 0,
      'attempts' => 0, 'expire' => 3600, 'dispatch_time' => 1999999000.0];
    $now = 2000003000.0;
    $store = $this->mockStore(
      static function () use (&$state): array { return $state; },
      static function (string $id, array $change) use (&$state, $now): bool {
        if (($change['deadline'] ?? 0) > 0
          && (($change['expected']['queueToken'] ?? null) !== ($state['queueToken'] ?? null)
            || ($change['expected']['queueState'] ?? null) !== ($state['queueState'] ?? null)
            || ($state['leaseUntil'] ?? 0) <= $now)
        ) {
          return false;
        }
        $state = $change['value'] ?? null;
        return true;
      }
    );
    $store->method('now')->willReturnCallback(static fn (): float => $now);
    $store->method('decodeQueueDelivery')->willReturn(['topic' => 'demo', 'data' => ['ok' => true]]);
    $manager = new ExposedTaskManager(new TaskQueue($store), ['demo' => static fn (): string => 'ran']);
    $task = new SwooleTask();
    $task->data = $state;
    $result = $manager->dispatchTask($this->mockServer(), $task);
    self::assertFalse($result);
    self::assertSame('failed', $state['queueState'] ?? null);
  }
}
