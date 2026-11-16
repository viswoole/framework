<?php

/**
 * 职责：管理持久化任务状态、租约和恢复；不执行业务处理器。
 */
declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use RuntimeException;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\QueueStoreInterface;

/**
 * 通过带令牌的条件提交实现至少一次消费，成功后才删除任务。
 */
final class TaskQueue
{
  use TaskQueueSubmission;
  use TaskQueueRecovery;

  /**
   * @var string 活跃任务分组前缀
   */
  public const string TAG_PREFIX = '$_TASK_QUEUE_';
  /**
   * @var string 失败任务索引
   */
  public const string FAILED_TAG = '$_TASK_FAILED_';
  /**
   * @var int 默认执行与投递租约秒数
   */
  public const int LEASE_SECONDS = 30;
  /**
   * @var QueueStoreInterface 原子存储边界
   */
  private readonly QueueStoreInterface $store;
  /**
   * @var array 重试策略，次数包含首次执行
   */
  private array $retry = ['max_attempts' => 5, 'base_seconds' => 5, 'max_seconds' => 300];

  /**
   * 初始化队列边界并校验驱动能力
   * @param CacheDriverInterface $store 原子队列驱动
   * @param int $leaseSeconds 租约秒数
   * @throws RuntimeException 驱动不支持可靠队列时抛出
   * @throws \InvalidArgumentException 租约不是正数时抛出
   */
  public function __construct(
    CacheDriverInterface $store,
    /**
     * @var int 投递与执行租约秒数
     */ public readonly int $leaseSeconds = self::LEASE_SECONDS,
  )
  {
    // 在当前作用域内做 instanceof 守卫，使 PHP 原生类型收窄生效，避免静态分析器将
    // isSupported() 的布尔结果视为无法推导类型（跨越静态方法调用的收窄不可见）
    if (!$store instanceof QueueStoreInterface
      || !$store->supportsQueueOperations()
      || !$store->supportsAtomicOperations()
    ) {
      throw new RuntimeException('可靠任务队列要求 Redis 原子状态提交能力');
    }
    if ($leaseSeconds < 1) { throw new \InvalidArgumentException('任务租约必须不小于 1 秒'); }
    $this->store = $store;
  }

  /**
   * 检测驱动是否具备可靠队列能力
   * @param CacheDriverInterface $store 缓存驱动
   * @return bool 是否支持可靠队列
   */
  public static function isSupported(CacheDriverInterface $store): bool
  {
    return $store instanceof QueueStoreInterface && $store->supportsQueueOperations() && $store->supportsAtomicOperations();
  }

  /**
   * 配置失败重试参数
   * @param array $retry 重试参数
   * @return void
   * @throws \InvalidArgumentException 任一重试配置不是正整数时抛出
   */
  public function configureRetry(array $retry): void
  {
    $validated = $this->retry;
    foreach ($validated as $name => $default) {
      $value = $retry[$name] ?? $default;
      if (!is_int($value) || $value < 1) { throw new \InvalidArgumentException(
        '无效队列重试配置：' . $name
      ); }
      $validated[$name] = $value;
    }
    $this->retry = $validated;
  }

  /**
   * 为任务状态刷新租约令牌与到期时间
   * @param array $data 当前状态
   * @param string $state 新租约状态
   * @return array 新状态与令牌
   * @throws \Random\RandomException 无法生成安全令牌时抛出
   */
  private function lease(array $data, string $state): array
  {
    $data['queueState'] = $state;
    $data['queueToken'] = bin2hex(random_bytes(24));
    // 租约时间统一取存储端时钟：Redis 侧按 redis TIME 校验到期，
    // 混用应用时钟会因跨机偏差导致提前失效或接管延迟。
    $data['leaseUntil'] = $this->store->now() + $this->leaseSeconds;
    return $data;
  }

  /**
   * 按比较交换语义原子提交状态与索引变更
   * @param array|null $before 期望旧状态
   * @param array|null $after 提交新状态
   * @param bool $isLeaseRequired 是否由 Redis 再次校验租约未到期
   * @return bool 是否提交成功
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  private function commit(?array $before, ?array $after, bool $isLeaseRequired = false): bool
  {
    $record = $after ?? $before;
    return $this->store->updateQueue($record['queueId'], [
      'expected' => $before, 'value' => $after,
      'deadline' => $isLeaseRequired ? $before['leaseUntil'] : 0,
      'remove' => $before === null ? '' : $this->index($before),
      'add' => $after === null ? '' : $this->index($after),
    ]);
  }

  /**
   * 解析任务状态对应的集合索引键
   * @param array $data 任务状态
   * @return string 逻辑索引键
   */
  private function index(array $data): string
  {
    return $this->store->getTagKey(
      ($data['queueState'] ?? '') === 'failed'
        ? self::FAILED_TAG : self::TAG_PREFIX . $data['queueGroup']
    );
  }

  /**
   * 认领任务执行权并推进执行租约
   * @param array $data 投递载荷
   * @return array|null 执行租约；重复或失效投递返回 null
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function consume(array $data): ?array
  {
    $before = $this->owned($data, 'dispatched');
    if ($before === null) { return null; }
    $after = $this->lease($before, 'running');
    $after['attempts'] = ($before['attempts'] ?? 0) + 1;
    return $this->commit($before, $after, true) ? $after : null;
  }

  /**
   * 校验当前状态持有指定令牌且租约未过期
   * @param array $data 令牌载荷
   * @param string $state 要求的状态
   * @return array|null 当前持有且未过期的状态
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  private function owned(array $data, string $state = 'running'): ?array
  {
    $current = $this->store->getQueueMetadata($data['queueId']);
    if (!is_array($current) || ($current['queueState'] ?? '') !== $state) { return null; }
    if (($current['queueToken'] ?? null) !== ($data['queueToken'] ?? null)) { return null; }
    return ($current['leaseUntil'] ?? 0) > $this->store->now() ? $current : null;
  }

  /**
   * 确认任务完成并从队列移除
   * @param array $data 执行令牌载荷
   * @return bool 是否成功确认
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function ack(array $data): bool
  {
    $before = $this->owned($data);
    return $before !== null && $this->commit($before, null, true);
  }

  /**
   * 续期当前执行租约
   * @param array $data 执行令牌载荷
   * @return bool 是否成功续期
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function renew(array $data): bool
  {
    $before = $this->owned($data);
    if ($before === null) { return false; }
    $after = $before;
    $after['leaseUntil'] = $this->store->now() + $this->leaseSeconds;
    return $this->commit($before, $after, true);
  }

  /**
   * 记录任务执行失败并进入重试或失败队列
   * @param array $data 执行令牌载荷
   * @param string $reason 错误原因
   * @param bool $terminal 是否直接进入失败队列
   * @return bool 是否成功记录
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  public function fail(array $data, string $reason, bool $terminal = false): bool
  {
    $before = $this->owned($data);
    return $before !== null && $this->transitionFailure($before, $reason, ['terminal' => $terminal]);
  }

  /**
   * 按重试次数决定进入重试状态或失败队列
   * @param array $before 当前状态
   * @param string $reason 错误原因
   * @param array $options 不可重试及过期恢复标记
   * @return bool 是否成功提交
   * @throws \Throwable 存储操作或相关回调失败且无法在本层处理时抛出
   */
  private function transitionFailure(array $before, string $reason, array $options = []): bool
  {
    $after = $before;
    $after['queueState'] = ($options['terminal'] ?? false) || $before['attempts'] >= $this->retry['max_attempts'] ? 'failed' : 'retry';
    $after['lastError'] = $reason;
    // 失败时间与重试时间同取存储端时钟，与恢复扫描的到期判定保持同一时钟域
    $after['failedAt'] = $now = $this->store->now();
    $after['leaseUntil'] = 0;
    $after['retryAt'] = $now + $this->delay($before['attempts']);
    return $this->commit($before, $after, !($options['isExpired'] ?? false));
  }

  /**
   * 计算带抖动的指数退避等待秒数
   * @param int $attempts 已执行次数
   * @return float 带抖动且不超过上限的退避秒数
   * @throws \Random\RandomException 随机数生成失败时抛出
   */
  private function delay(int $attempts): float
  {
    $base = min(
      $this->retry['max_seconds'],
      $this->retry['base_seconds'] * 2 ** min(30, max(0, $attempts - 1))
    );
    return $base * random_int(800, 1000) / 1000;
  }

  /**
   * 在取得执行租约之后恢复业务数据，首次和重试使用同一字节载荷。
   * @param array $delivery 投递信封
   * @return array 已解码任务
   * @throws \Throwable 载荷或业务对象恢复失败时抛出
   */
  public function decodeDelivery(array $delivery): array
  {
    return $this->store->decodeQueueDelivery($delivery);
  }


}
