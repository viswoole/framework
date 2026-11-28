<?php

/**
 * 职责：监控并透传缓存操作与底层原子能力；不采集业务载荷。
 */
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

namespace Viswoole\Cache;

use Closure;
use DateTime;
use RuntimeException;
use Viswoole\Cache\Contract\AtomicCacheDriverInterface;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\CacheTagInterface;
use Viswoole\Cache\Contract\QueueStoreInterface;
use Viswoole\Monitor\TraceContext;

/**
 * 缓存驱动监控装饰器
 *
 * 职责：包装任意缓存驱动，将读写操作按商店名与键采集到当前请求的
 * 慢请求记录中。
 * 边界：保留驱动的返回语义与异常语义；仅采集键和结果摘要，不采集
 * 缓存值；是否采集由当前请求上下文决定（无上下文时零开销直通）。
 *
 * @see CacheDriverInterface 被装饰的驱动接口
 */
final readonly class MonitoredDriver implements QueueStoreInterface
{
  /**
   * 构造装饰器
   *
   * @param CacheDriverInterface $driver 被包装的原始驱动
   * @param string $store 所属缓存商店名（小写），仅用于采集记录
   */
  public function __construct(
    /**
     * @var CacheDriverInterface 被包装的底层驱动
     */ private CacheDriverInterface $driver,
    /**
     * @var string 当前商店的监控名称
     */ private string $store
  )
  {
  }

  /**
   * 透传只读元数据分页
   *
   * @param array $filter 筛选条件
   * @param string $cursor 前页游标
   * @param int $limit 返回上限
   * @return array{list:list<array>,cursor:string,errors:list<array{queueId:string,reason:string}>,isComplete:bool} 元数据页
   * @throws \Throwable 底层不支持队列或读取失败
   */
  public function queryQueueMetadata(array $filter = [], string $cursor = '', int $limit = 20): array
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持队列查询'); }
    return $this->invoke('queryQueueMetadata', [$filter, $cursor, $limit]);
  }

  /**
   * 透传只读元数据详情
   *
   * @param string $queueId 队列身份
   * @return array|null 元数据详情
   * @throws \Throwable 底层不支持队列或读取失败
   */
  public function inspectQueueMetadata(string $queueId): ?array
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持队列查询'); }
    return $this->invoke('inspectQueueMetadata', [$queueId]);
  }

  /**
   * 委托底层的原子集合回收，并保留监控采集。
   * @param string $key 集合键
   * @param string $registry 注册集合键
   * @param string|null $member 待移除成员
   * @return void
   */
  public function pruneSet(string $key, string $registry, ?string $member = null): void
  {
    if (!$this->supportsAtomicOperations()) { throw new RuntimeException(
      '缓存驱动不支持原子集合回收'
    ); }
    $this->invoke('pruneSet', [$key, $registry, $member]);
  }

  /**
   * 判定底层驱动是否支持原子操作
   * @return bool 只报告底层驱动实际提供的原子能力
   */
  public function supportsAtomicOperations(): bool
  {
    return $this->driver instanceof AtomicCacheDriverInterface && $this->driver->supportsAtomicOperations();
  }

  /**
   * 判定底层驱动是否支持可靠队列
   * @return bool 底层驱动是否支持可靠队列，装饰器不提升能力
   */
  public function supportsQueueOperations(): bool
  {
    return $this->driver instanceof QueueStoreInterface
      && $this->driver->supportsQueueOperations();
  }

  /**
   * 委托队列原子提交，不能将普通缓存提升为可靠队列驱动。
   * @param string $key 任务键
   * @param array $change 条件与状态变更
   * @return bool 是否提交成功
   * @throws RuntimeException 底层驱动不支持可靠队列时抛出
   * @throws \Throwable 底层存储操作失败时抛出
   */
  public function updateQueue(string $key, array $change): bool
  {
    if (!$this->supportsQueueOperations()) {
      throw new RuntimeException('缓存驱动不支持可靠队列原子状态提交');
    }
    return $this->invoke('updateQueue', [$key, $change]);
  }

  /**
   * 转发节点容量查询
   *
   * 使用当前商店的任务命名空间，保持观察装饰器与底层驱动能力一致。
   *
   * @param string $instanceId 节点身份
   * @return int 当前有效分配数量
   * @throws \Throwable 查询失败
   */
  public function getQueueAssignmentCount(string $instanceId): int
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持可靠队列容量查询'); }
    return $this->invoke('getQueueAssignmentCount', [$instanceId]);
  }

  /**
   * 透传到期任务查询
   *
   * 固定窗口与偏移必须原样交给驱动，避免监控包装改变扫描公平性。
   *
   * @param int $limit 单轮数量上限
   * @param int $offset 到期集合起始偏移，用于跳过本轮无法领取的任务
   * @param float|null $dueBefore 固定到期扫描上界，null 使用存储端当前时间
   * @return array 底层驱动结果
   * @throws \Throwable 底层存储失败时抛出
   */
  public function getDueQueue(int $limit = 100, int $offset = 0, ?float $dueBefore = null): array
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持可靠队列调度'); }
    return $this->invoke('getDueQueue', [$limit, $offset, $dueBefore]);
  }

  /**
   * 透传任务元数据查询
   * @param string $key 任务标识
   * @return ?array 底层驱动结果
   * @throws \Throwable 底层存储失败时抛出
   */
  public function getQueueMetadata(string $key): ?array
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持可靠队列调度'); }
    return $this->invoke('getQueueMetadata', [$key]);
  }

  /**
   * 透传残留调度入口清理
   * @param string $key 任务标识
   * @return void 底层驱动结果
   * @throws \Throwable 底层存储失败时抛出
   */
  public function pruneMissingQueue(string $key): void
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持可靠队列调度'); }
    $this->invoke('pruneMissingQueue', [$key]);
  }

  /**
   * 透传不恢复业务对象的投递信封读取。
   * @param string $key 任务标识
   * @return array|null 投递信封
   * @throws \Throwable 底层存储失败时抛出
   */
  public function getQueueDelivery(string $key): ?array
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持可靠队列'); }
    return $this->invoke('getQueueDelivery', [$key]);
  }

  /**
   * 透传消费边界的业务载荷解码。
   * @param array $delivery 投递信封
   * @return array 完整任务
   * @throws \Throwable 解码失败时抛出
   */
  public function decodeQueueDelivery(array $delivery): array
  {
    if (!$this->supportsQueueOperations()) {
      throw new RuntimeException('缓存驱动不支持可靠队列');
    }
    // 解码不是缓存 I/O，不能将二进制信封作为监控键交给 invoke()。
    return $this->driver->decodeQueueDelivery($delivery);
  }

  /**
   * 透传存储端时钟读取，供队列租约与调度统一时钟域。
   * @return float 存储端当前时间戳（浮点秒）
   * @throws \Throwable 存储操作失败时抛出
   */
  public function now(): float
  {
    if (!$this->supportsQueueOperations()) {
      throw new RuntimeException('缓存驱动不支持可靠队列');
    }
    // 时钟读取非缓存 I/O，且调用频率高，不进入监控采集。
    return $this->driver->now();
  }

  /**
   * 透传按快照校验的损坏任务隔离。
   * @param string $key 任务标识
   * @param string $snapshot 原始字节
   * @param string $reason 解析失败原因
   * @return bool 是否隔离成功
   * @throws \Throwable 存储操作失败时抛出
   */
  public function quarantineQueue(string $key, string $snapshot, string $reason): bool
  {
    if (!$this->supportsQueueOperations()) { throw new RuntimeException('缓存驱动不支持可靠队列'); }
    return $this->invoke('quarantineQueue', [$key, $snapshot, $reason]);
  }

  /**
   * 采集并执行驱动操作
   *
   * @param string $method 操作名
   * @param array $arguments 调用参数
   * @return mixed 驱动返回值
   */
  private function invoke(string $method, array $arguments): mixed
  {
    return TraceContext::measure([
      'type' => 'cache', 'store' => $this->store, 'operation' => $method,
      'key' => $arguments[0] ?? null,
    ], fn (): mixed => $this->driver->$method(...$arguments));
  }

  /**
   * 创建标签实例
   *
   * @param array|string $tag 标签名，支持批量
   * @return CacheTagInterface 标签操作装饰器
   */
  public function tag(array|string $tag): CacheTagInterface
  {
    // 基于原始驱动创建，避免标签内部操作被双重装饰
    return new MonitoredTag($tag, $this->driver, $this->store);
  }

  /**
   * 设置序列化方法
   *
   * @param Closure|string $set 序列化方法名或闭包
   * @param Closure|string $get 反序列化方法名或闭包
   * @return static 返回当前实例，支持链式调用
   */
  public function setSerialize(
    Closure|string $set = 'serialize',
    Closure|string $get = 'unserialize'
  ): static
  {
    $this->driver->setSerialize($set, $get);
    return $this;
  }

  /**
   * 析构方法
   *
   * 仅满足 CacheDriverInterface 的析构契约，不做任何操作：驱动生命周期
   * 仍由原实例管理（CacheManager 常驻持有，随进程退出释放），装饰器析构
   * 若转发 close 会与驱动自身的析构释放重复执行，导致共享连接被提前关闭。
   */
  public function __destruct()
  {
  }

  /**
   * 自增缓存
   *
   * @param string $key 缓存键名
   * @param int $step 自增步长
   * @return false|int 自增后的值，失败返回 false
   */
  public function inc(string $key, int $step = 1): false|int
  {
    return $this->invoke('inc', [$key, $step]);
  }

  /**
   * 自减缓存
   *
   * @param string $key 缓存键名
   * @param int $step 自减步长
   * @return false|int 自减后的值，失败返回 false
   */
  public function dec(string $key, int $step = 1): false|int
  {
    return $this->invoke('dec', [$key, $step]);
  }

  /**
   * 设置缓存
   *
   * @param string $key 缓存键名
   * @param mixed $value 缓存值（不采集）
   * @param DateTime|int|null $expire 过期时间，null 表示长期有效
   * @param bool $NX 是否仅在键不存在时写入
   * @return bool 设置成功返回 true
   */
  public function set(
    string $key,
    mixed $value,
    DateTime|int|null $expire = null,
    bool $NX = false
  ): bool
  {
    return $this->invoke('set', [$key, $value, $expire, $NX]);
  }

  /**
   * 读取缓存
   *
   * @param string $key 缓存键名
   * @param mixed $default 键不存在时的默认值
   * @return mixed 缓存值（不采集）或默认值
   */
  public function get(string $key, mixed $default = null): mixed
  {
    return $this->invoke('get', [$key, $default]);
  }

  /**
   * 读取并删除缓存
   *
   * @param string $key 缓存键名
   * @return mixed 缓存值（不采集），不存在时返回 null
   */
  public function pull(string $key): mixed
  {
    return $this->invoke('pull', [$key]);
  }

  /**
   * 判断缓存是否存在
   *
   * @param string $key 缓存键名
   * @return bool 存在返回 true
   */
  public function has(string $key): bool
  {
    return $this->invoke('has', [$key]);
  }

  /**
   * 清除当前商店全部缓存
   *
   * @return bool 清除成功返回 true
   */
  public function clear(): bool
  {
    return $this->invoke('clear', []);
  }

  /**
   * 删除缓存
   *
   * @param array|string $keys 缓存键名，支持批量
   * @return false|int 成功删除的键数量，失败返回 false
   */
  public function delete(array|string $keys): false|int
  {
    return $this->invoke('delete', [$keys]);
  }

  /**
   * 获取竞争锁
   *
   * @param string $scene 锁场景名
   * @param int $expire 锁过期时间（秒）
   * @param bool $autoUnlock 是否自动解锁
   * @param int $retry 获取失败重试次数
   * @param int|float $sleep 重试间隔（秒）
   * @return string 锁唯一标识，用于 unlock
   */
  public function lock(
    string $scene,
    int $expire = 10,
    bool $autoUnlock = false,
    int $retry = 5,
    int|float $sleep = 0.2
  ): string
  {
    return $this->invoke('lock', [$scene, $expire, $autoUnlock, $retry, $sleep]);
  }

  /**
   * 释放锁
   *
   * @param string $id lock 返回的锁唯一标识
   * @return bool 释放成功返回 true
   */
  public function unlock(string $id): bool
  {
    return $this->invoke('unlock', [$id]);
  }

  /**
   * 获取缓存剩余有效期
   *
   * @param string $key 缓存键名
   * @return false|int 剩余秒数，-1 为长期有效，false 为不存在或已过期
   */
  public function ttl(string $key): false|int
  {
    return $this->invoke('ttl', [$key]);
  }

  /**
   * 往数组集合中追加值
   *
   * @param string $key 集合键名
   * @param array|string $values 追加的值，支持批量
   * @return false|int 追加成功的数量，失败返回 false
   */
  public function sAddArray(string $key, array|string $values): false|int
  {
    return $this->invoke('sAddArray', [$key, $values]);
  }

  /**
   * 从数组集合中删除值
   *
   * @param string $key 集合键名
   * @param array|string $values 删除的值，支持批量
   * @return false|int 删除成功的数量，失败返回 false
   */
  public function sRemoveArray(string $key, array|string $values): false|int
  {
    return $this->invoke('sRemoveArray', [$key, $values]);
  }

  /**
   * 获取数组集合
   *
   * @param string $key 集合键名
   * @return array|false 集合内容（不采集），不存在时返回 false
   */
  public function getArray(string $key): array|false
  {
    return $this->invoke('getArray', [$key]);
  }

  /**
   * 获取所有缓存标签
   *
   * @return array|false 标签列表，失败返回 false
   */
  public function getTags(): array|false
  {
    return $this->invoke('getTags', []);
  }

  /**
   * 获取标签键
   *
   * 元数据查询不采集计时。
   *
   * @param string $tag 标签名
   * @return string 标签实际存储键名
   */
  public function getTagKey(string $tag): string
  {
    return $this->driver->getTagKey($tag);
  }

  /**
   * 获取标签仓库名称
   *
   * 元数据查询不采集计时。
   *
   * @return string 标签仓库名称
   */
  public function getTagStoreName(): string
  {
    return $this->driver->getTagStoreName();
  }

  /**
   * 获取实际的缓存标识
   *
   * 元数据查询不采集计时。
   *
   * @param string $key 缓存键名
   * @return string 加商店前缀后的实际键名
   */
  public function getCacheKey(string $key): string
  {
    return $this->driver->getCacheKey($key);
  }

  /**
   * 获取底层连接句柄
   *
   * @return mixed 连接句柄
   */
  public function connect(): mixed
  {
    return $this->driver->connect();
  }

  /**
   * 关闭连接句柄
   *
   * 显式调用时透传给被包装驱动；装饰器自身析构不触发关闭。
   */
  public function close(): void
  {
    $this->driver->close();
  }
}
