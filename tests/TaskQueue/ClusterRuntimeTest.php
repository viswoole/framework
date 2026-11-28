<?php

declare(strict_types=1);

/* 职责：用两个真实多进程 Swoole 节点验证框架共享消费、并发预算、崩溃接管和本机同步执行。 */

namespace Viswoole\Tests\TaskQueue;

use Closure;
use PHPUnit\Framework\TestCase;
use Redis;
use Viswoole\Tests\TaskQueue\Support\ClusterNode;

require_once __DIR__ . '/Support/ClusterNode.php';

/**
 * 框架集群任务运行时测试
 */
final class ClusterRuntimeTest extends TestCase
{
  /**
   * @var Redis 测试独占连接
   */
  private Redis $redis;
  /**
   * @var string 随机测试前缀
   */
  private string $prefix;
  /**
   * @var array<int,ClusterNode> 待清理的节点进程
   */
  private array $nodes = [];

  /**
   * 创建本轮命名空间
   *
   * 使用隔离 Redis，不启动业务应用。
   *
   * @return void 测试存储已连接
   */
  protected function setUp(): void
  {
    $this->prefix = 'cluster-runtime:' . bin2hex(random_bytes(8)) . ':';
    $this->redis = new Redis();
    $this->redis->connect(getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1', 6379, 2);
  }

  /**
   * 回收所有节点和随机键
   *
   * 先终止进程树，避免清理 Redis 后仍有后台写入。
   *
   * @return void 测试资源已回收
   */
  protected function tearDown(): void
  {
    foreach ($this->nodes as $node) { $node->close(); }
    $keys = $this->redis->keys($this->prefix . '*');
    if ($keys !== []) { $this->redis->del($keys); }
  }

  /**
   * 从一个生产节点投递后由两个节点执行
   *
   * 逐任务核对稳定身份和完成次数，同步调用仍须在各自本机返回。
   *
   * @return void 分担、容量和同步契约正确
   */
  public function testTwoNodesShareProducerWorkAndSyncStaysLocal(): void
  {
    $first = $this->startNode('a');
    $second = $this->startNode('b');
    $ids = $first->request('/enqueue?count=12&duration=0.15')['ids'];
    $this->await(fn (): bool => $this->redis->hLen($this->prefix . 'results') === 12);
    $results = $this->results();
    $actualNodes = array_values(array_unique(array_column($results, 'node')));
    sort($actualNodes);
    self::assertSame(['a', 'b'], $actualNodes);
    self::assertEqualsCanonicalizing($ids, array_column($results, 'queueId'));
    self::assertSame(['1'], array_values(array_unique($this->redis->hVals($this->prefix . 'completions'))));
    self::assertSame('1', $this->redis->hGet($this->prefix . 'max', 'a'));
    self::assertSame('1', $this->redis->hGet($this->prefix . 'max', 'b'));
    self::assertSame('a', $first->request('/sync')['result']);
    self::assertSame('b', $second->request('/sync')['result']);
  }

  /**
   * 执行节点退出后保留同一逻辑身份
   *
   * 在真实 Task Worker 已进入处理器后终止节点，另一节点按租约与重试流程接管。
   *
   * @return void 原 ID 被接管且只产生一次完成效果
   */
  public function testNodeCrashIsRecoveredByOtherNode(): void
  {
    $first = $this->startNode('a');
    $id = $first->request('/enqueue?count=1&duration=1')['ids'][0];
    $this->await(fn (): bool => $this->redis->hGet($this->prefix . 'started', $id) === 'a');
    $first->close();
    $this->startNode('b');
    $this->await(fn (): bool => $this->redis->hExists($this->prefix . 'results', $id));
    $result = $this->results()[$id];
    self::assertSame('b', $result['node']);
    self::assertSame($id, $result['queueId']);
    self::assertSame('a', $result['producer']);
    self::assertSame(2, $result['attempts']);
    self::assertSame('1', $this->redis->hGet($this->prefix . 'completions', $id));
  }

  /**
   * 停机节点不再抢占积压任务
   *
   * 原生停机前设置框架停机状态，已进入处理器的任务仍可正常完成。
   *
   * @return void 积压由剩余节点排空
   */
  public function testStoppingNodeDrainsWithoutNewClaims(): void
  {
    $first = $this->startNode('a');
    $this->startNode('b');
    $first->request('/enqueue?count=10&duration=0.2');
    $this->await(fn (): bool => in_array('a', $this->redis->hVals($this->prefix . 'started'), true));
    self::assertSame('a', $first->request('/stop')['node']);
    $this->await(fn (): bool => $this->redis->hLen($this->prefix . 'results') === 10);
    self::assertLessThanOrEqual(1, count(array_filter($this->results(), static fn (array $row): bool => $row['node'] === 'a')));
    self::assertSame(['1'], array_values(array_unique($this->redis->hVals($this->prefix . 'completions'))));
  }

  /**
   * 启动并等待节点 HTTP 就绪
   *
   * 注册清理早于探活，失败也不能残留进程。
   *
   * @param string $name 节点身份
   * @return ClusterNode 已就绪节点
   */
  private function startNode(string $name): ClusterNode
  {
    $node = new ClusterNode($name, $this->prefix);
    $this->nodes[] = $node;
    $this->await(fn (): bool => $node->request('/') !== null);
    return $node;
  }

  /**
   * 等待可观察状态
   *
   * 不依赖固定休眠猜测是否执行，超时给出明确失败。
   *
   * @param Closure $condition 完成条件
   * @return void 条件已满足
   */
  private function await(Closure $condition): void
  {
    $deadline = microtime(true) + 12;
    do {
      if ($condition()) { return; }
      usleep(20000);
    } while (microtime(true) < $deadline);
    self::fail('集群任务状态未在12秒内达到预期');
  }

  /**
   * 读取真实处理器完成结果
   *
   * 结果按队列身份索引，不按本机 Swoole IPC 身份索引。
   *
   * @return array<string,array> 所有已完成结果
   */
  private function results(): array
  {
    return array_map(static fn (string $row): array => json_decode($row, true, flags: JSON_THROW_ON_ERROR), $this->redis->hGetAll($this->prefix . 'results'));
  }
}
