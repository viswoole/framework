<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Core\Server;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Viswoole\Cache\Contract\AtomicCacheDriverInterface;
use Viswoole\Cache\Contract\CacheDriverInterface;

/**
 * 持久化待消费任务的索引、投递租约和消费边界；不执行主题处理器。
 * Redis 的 NX 与 pull 分别提供跨实例认领和至多一次消费保证。
 */
final class TaskQueue
{
  public const string TAG_PREFIX = '$_TASK_QUEUE_';
  /**
   * 租约与恢复扫描周期的默认值，可通过构造参数覆盖
   */
  public const int LEASE_SECONDS = 30;
  private const string CLAIM_PREFIX = '$_TASK_CLAIM_';

  private readonly AtomicCacheDriverInterface $store;
  /**
   * @var int 投递租约与恢复扫描周期（秒），实例崩溃后未消费任务最长等待该时长被接管
   */
  public readonly int $leaseSeconds;
  /**
   * @var Closure|null 索引清理失败回调（消息，异常）；未注入时回退 error_log
   */
  private readonly ?Closure $onIndexCleanupFailure;

  /**
   * @param CacheDriverInterface $store 支持原子能力的缓存驱动
   * @param int $leaseSeconds 投递租约与恢复扫描周期（秒），过短会放大重复投递窗口
   * @param Closure|null $onIndexCleanupFailure 索引清理失败回调，参数为（消息，异常）；
   *   生产环境由 TaskManager 注入框架日志通道，缺省回退 error_log 以保持本类对日志门面的零依赖
   * @throws InvalidArgumentException 租约周期小于 1 秒时抛出
   */
  public function __construct(
    CacheDriverInterface $store,
    int                  $leaseSeconds = self::LEASE_SECONDS,
    ?Closure             $onIndexCleanupFailure = null
  )
  {
    // 直接检查接口，让静态分析能在属性赋值前收窄驱动类型。
    if (!$store instanceof AtomicCacheDriverInterface || !$store->supportsAtomicOperations()) {
      throw new RuntimeException('持久化任务队列要求支持原子 NX、pull 和集合回收的缓存驱动，请将 task.store 配置为 Redis 商店');
    }
    if ($leaseSeconds < 1) {
      throw new InvalidArgumentException('任务投递租约周期必须不小于 1 秒');
    }
    $this->store = $store;
    $this->leaseSeconds = $leaseSeconds;
    $this->onIndexCleanupFailure = $onIndexCleanupFailure;
  }

  /**
   * @return bool 驱动及其装饰器是否提供队列需要的原子能力
   */
  public static function isSupported(CacheDriverInterface $store): bool
  {
    return $store instanceof AtomicCacheDriverInterface && $store->supportsAtomicOperations();
  }

  /**
   * 保存任务及原始归属，恢复后不能使用新投递者的 Worker ID 清理索引。
   * @param array $data 任务载荷
   * @param string $group 实例与 Worker 组成的分组
   * @return array 可投递的完整任务载荷
   */
  public function persist(array $data, string $group): array
  {
    $data['queueId'] = bin2hex(random_bytes(24));
    $data['queueGroup'] = $group;
    // 首次投递也先建立租约，避免持久化后被恢复扫描抢先投递。
    if (!$this->store->set(self::CLAIM_PREFIX . $data['queueId'], true, $this->leaseSeconds, true)) {
      throw new RuntimeException('创建任务投递租约失败');
    }
    if (!$this->store->tag(self::TAG_PREFIX . $group)->set($data['queueId'], $data, 0)) {
      throw new RuntimeException('将任务数据写入到缓存队列失败！');
    }
    return $data;
  }

  /**
   * 认领后投递，保留原始数据以覆盖认领者在投递前崩溃的窗口。
   * @param string $queueId 持久化任务 ID
   * @param Closure $dispatch 投递函数，返回任务 ID 或 false
   * @param string|null $group 旧格式任务的原始分组
   * @return int|false 投递结果；任务已被认领或消费时返回 false
   */
  public function dispatch(string $queueId, Closure $dispatch, ?string $group = null): int|false
  {
    if (!$this->store->set(self::CLAIM_PREFIX . $queueId, true, $this->leaseSeconds, true)) return false;
    $data = $this->store->get($queueId);
    if (!is_array($data)) return false;
    // 不主动删除租约，避免迟到的投递者误删到期后由其他实例取得的租约。
    // 失败或崩溃均由下一次周期扫描在租约到期后重试。
    $data['queueGroup'] ??= $group;
    return $dispatch($data);
  }

  /**
   * 原子取得消费权，再清理原始分组；只有胜者能进入主题处理器。
   * @param array $data 投递载荷
   * @param string $workerId 旧格式载荷的原始 Worker ID
   * @return bool 是否取得消费权
   */
  public function consume(array $data, string $workerId): bool
  {
    $queueId = $data['queueId'];
    $stored = $this->store->pull($queueId);
    if (!is_array($stored)) return false;
    $group = $stored['queueGroup'] ?? $data['queueGroup'] ?? $workerId;
    try {
      $this->removeIndex($queueId, self::TAG_PREFIX . $group);
    } catch (Throwable $exception) {
      // 消费权已取得，辅助索引故障不能阻止业务执行；残留成员由周期扫描重试清理。
      $this->logIndexCleanupFailure("任务索引清理失败，将由恢复扫描重试：$queueId", $exception);
    }
    return true;
  }

  /**
   * 报告索引清理失败；日志通道自身的故障不允许打断任务执行流程。
   * @param string $message 失败描述
   * @param Throwable $exception 原始异常
   * @return void
   */
  private function logIndexCleanupFailure(string $message, Throwable $exception): void
  {
    if ($this->onIndexCleanupFailure !== null) {
      try {
        ($this->onIndexCleanupFailure)($message, $exception);
        return;
      } catch (Throwable $logFailure) {
        // 日志通道故障不向外抛，避免打断消费流程；降级到标准错误保留痕迹。
        $message .= '；日志通道异常：' . $logFailure->getMessage();
      }
    }
    // 独立使用（如测试环境）时回退到标准错误，保持本类对日志门面的零依赖。
    error_log("[Viswoole TaskQueue] {$message}；{$exception->getMessage()}");
  }

  /**
   * 清除明确投递失败的原始任务，保持 emit() 的失败返回契约。
   * @param array $data 持久化任务载荷
   * @return void
   */
  public function discard(array $data): void
  {
    if ($this->store->delete($data['queueId']) === false) {
      throw new RuntimeException('删除投递失败的任务数据失败：' . $data['queueId']);
    }
    $this->removeIndex($data['queueId'], self::TAG_PREFIX . $data['queueGroup']);
  }

  /**
   * 扫描所有实例及旧 Worker 分组，避免实例下线或 Worker 数缩减后任务失联。
   * @param Closure $dispatch 投递函数
   * @return void
   */
  public function recover(Closure $dispatch): void
  {
    $prefix = $this->store->getTagKey(self::TAG_PREFIX);
    foreach ($this->store->getTags() ?: [] as $tag) {
      if (!str_starts_with($tag, $prefix)) continue;
      $queueIds = $this->store->getArray($tag) ?: [];
      if ($queueIds === []) {
        $this->store->pruneSet($tag, $this->store->getTagStoreName());
        continue;
      }
      foreach ($queueIds as $queueId) {
        if (!$this->store->has($queueId)) {
          $this->removeIndex($queueId, $tag, true);
          continue;
        }
        $this->dispatch($queueId, $dispatch, substr($tag, strlen($prefix)));
      }
    }
  }

  /**
   * 原子移除成员并回收空分组；并发写入的新成员会保留恢复入口。
   * @param string $queueId 任务 ID
   * @param string $tag 标签名或驱动生成的标签键
   * @param bool $isTagKey 是否已为驱动生成的标签键
   * @return void
   */
  private function removeIndex(string $queueId, string $tag, bool $isTagKey = false): void
  {
    $key = $isTagKey ? $tag : $this->store->getTagKey($tag);
    $this->store->pruneSet($key, $this->store->getTagStoreName(), $queueId);
  }
}
