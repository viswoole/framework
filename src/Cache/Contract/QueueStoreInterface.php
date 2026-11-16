<?php
/**
 * 职责：定义可靠队列的条件写入边界；不负责消费策略。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Contract;

/**
 * 将任务状态与索引变更作为一个不可分割的操作。
 */
interface QueueStoreInterface extends AtomicCacheDriverInterface
{
  /**
   * @var string 到期索引逻辑键
   */
  public const string SCHEDULE_KEY = '$_TASK_SCHEDULE_';
  /**
   * @var string 任务标识到分组索引的原子维护目录
   */
  public const string DIRECTORY_KEY = '$_TASK_INDEX_DIRECTORY_';
  /**
   * @var string 隔离记录逻辑键前缀
   */
  public const string QUARANTINE_PREFIX = '$_TASK_QUARANTINE_RECORD_';
  /**
   * @var string 隔离记录标签
   */
  public const string QUARANTINE_TAG = '$_TASK_QUARANTINE_';

  /**
   * 判定当前驱动是否提供可靠队列操作
   * @return bool 当前底层是否提供可靠队列操作
   */
  public function supportsQueueOperations(): bool;

  /**
   * 获取到期任务标识，不读取业务载荷。
   * @param int $limit 单轮最多返回数量
   * @return list<string> 到期任务标识
   * @throws \Throwable Redis 查询失败时抛出
   */
  public function getDueQueue(int $limit = 100): array;

  /**
   * 读取任务元数据，常规认领与续租不恢复业务对象。
   * @param string $key 任务标识
   * @return array|null 元数据，任务不存在时返回 null
   * @throws \Throwable 读取或协议解析失败时抛出
   */
  public function getQueueMetadata(string $key): ?array;

  /**
   * 读取仅含元数据与序列化字节的投递信封，不恢复业务对象。
   * @param string $key 任务标识
   * @return array|null 投递信封
   * @throws \Throwable 存储或记录解析失败时抛出
   */
  public function getQueueDelivery(string $key): ?array;

  /**
   * 在 Task Worker 执行边界恢复业务载荷。
   * @param array $delivery 投递信封
   * @return array 完整任务载荷
   * @throws \Throwable 载荷解码或业务对象恢复失败时抛出
   */
  public function decodeQueueDelivery(array $delivery): array;

  /**
   * 按原始快照原子隔离损坏记录，不能覆盖并发修复的记录。
   * @param string $key 任务标识
   * @param string $snapshot 原始字节
   * @param string $reason 解析失败原因
   * @return bool 是否成功隔离
   * @throws \Throwable 存储操作失败时抛出
   */
  public function quarantineQueue(string $key, string $snapshot, string $reason): bool;

  /**
   * 仅在任务数据仍不存在时回收残留调度入口。
   * @param string $key 任务标识
   * @return void
   * @throws \Throwable Redis 操作失败时抛出
   */
  public function pruneMissingQueue(string $key): void;

  /**
   * 以存储端时钟返回当前时间戳（浮点秒）。
   * 租约与调度时间必须以存储端时钟为唯一时钟域，避免应用与存储的
   * 时钟偏差导致租约提前失效或接管延迟。
   * @return float 存储端当前时间戳
   * @throws \RedisException 存储时钟查询失败时抛出
   */
  public function now(): float;

  /**
   * 校验版本或执行权后更新任务及索引，null 原值表示要求键不存在。
   * @param string $key 任务键
   * @param array{expected: ?array, value: ?array, remove?: string, add?: string, target?: string, deadline?: float|int} $change 状态与索引；deadline > 0 时按令牌、状态和存储中当前期限校验执行权；target 用于原子重放
   * @return bool 版本或执行权匹配且提交成功
   * @throws \RuntimeException 存储提交失败时抛出
   * @throws \RedisException Redis 操作失败时抛出
   */
  public function updateQueue(string $key, array $change): bool;
}
