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

namespace Viswoole\Core\Channel;

use Override;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;
use Viswoole\Core\Channel\Contract\ConnectionPoolInterface;
use Viswoole\Core\Exception\ConnectionPoolException;
use Viswoole\Core\Server\ServerEventHook;

/**
 * 连接池抽象基类
 *
 * 基于 Swoole\Coroutine\Channel 实现连接的借出、归还、填充和销毁，
 * 子类需实现 createConnection() 和 connectionDetection() 定义连接创建与可用性检测逻辑。
 * 内置递归深度限制防止无效连接导致无限递归。
 */
abstract class ConnectionPool implements ConnectionPoolInterface
{
  public const int DEFAULT_SIZE = 10;
  public const array ERROR_MESSAGE = [
    SWOOLE_CHANNEL_OK => '正常',
    SWOOLE_CHANNEL_TIMEOUT => '失败：-1 连接超时',
    SWOOLE_CHANNEL_CLOSED => '失败：-2 连接已关闭',
    SWOOLE_CHANNEL_CANCELED => '失败：-3 意外取消'
  ];
  /** @var int make() 最大递归深度，防止 createConnection() 持续返回无效连接导致无限递归 */
  private const int MAX_MAKE_DEPTH = 3;
  /** @var string 协程上下文中存储 make() 递归深度的键名（按协程隔离，互不干扰） */
  private const string MAKE_DEPTH_CONTEXT_KEY = 'connection_pool.make_depth';
  /**
   * Channel push/pop 的兜底超时（秒）
   *
   * Swoole 6.x 中 push/pop 超时为 -1 或 0 时均表示永久等待：
   * 一旦容量计数与队列实际状态出现瞬时错配，永久等待会直接演变为协程死锁
   * （所有协程 asleep，FATAL ERROR: deadlock），故所有 push/pop 均带有限超时兜底。
   */
  private const float CHANNEL_FALLBACK_TIMEOUT = 5.0;
  /** @var Channel|null 当前连接池, close 后置为 null */
  protected ?Channel $pool = null;
  /**
   * @var int 已创建连接总数（池内闲置 + 协程借出）
   *
   * 池容量必须约束"总连接数"而非"池内闲置数"：若仅按池内数量判断是否新建，
   * 高并发借出期池恒为空，会无限新建连接，容量限制形同虚设，
   * 多 worker 场景下总连接数随并发失控，极易打爆下游（如 MySQL max_connections）。
   * 协程为非抢占式调度，检查与自增之间无协程切换点，无需加锁。
   */
  private int $connectionCount = 0;

  /**
   * @param int $max_size 连接池最大容量
   * @param int|null $default_fill 初始填充连接数，为 null 时不自动填充
   */
  public function __construct(
    protected int $max_size = self::DEFAULT_SIZE,
    ?int          $default_fill = null
  )
  {
    $this->pool = new Channel($max_size);
    if ($default_fill) {
      // 在 workerStart 事件中填充连接池（每个 worker 进程独立填充）
      // 不能用 start 事件：onStart 在 master 进程触发，此时 worker 已 fork，
      // 主进程填充的连接无法共享给 worker 进程
      ServerEventHook::addEvent('workerStart', function () use ($default_fill) {
        $this->fill($default_fill);
      });
    }
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function fill(?int $size = null): void
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    $size = $size === null ? $this->max_size : $size;
    // 非协程环境无需预填充连接池，连接池仅在协程环境下才有复用意义
    if (!$this->isCoroutine()) return;
    // 按总连接数（闲置+借出）计算缺口，防止与借出中的连接叠加超容量
    while ($this->connectionCount < $size && $this->connectionCount < $this->max_size) {
      $this->make();
    }
  }

  /**
   * 判断当前是否在协程环境中运行
   *
   * @return bool 在协程中返回 true
   */
  private function isCoroutine(): bool
  {
    return Coroutine::getCid() > -1;
  }

  /**
   * 创建新连接并放入池中，递归深度超过最大次数时抛出异常防止无限递归
   *
   * 递归深度存储在协程上下文而非实例属性：
   *   多个协程共享同一个连接池实例，若用实例属性计数，并发协程各自的 make()
   *   会互相累加深度，导致误判"达到最大重试次数"（第 4 个并发协程建连即抛异常）。
   *   协程上下文按协程隔离，随协程销毁自动释放，无内存泄漏。
   * 非协程环境（CLI）无并发、也无 make()->put()->make() 递归路径（put() 直接关闭连接），
   *   因此无需深度限制，直接创建连接即可。
   *
   * @throws ConnectionPoolException 创建连接重试超过最大次数时抛出
   */
  protected function make(): void
  {
    // 非协程环境：无并发与递归路径，直接创建连接（连接池仅在协程环境下有复用意义）
    if (!$this->isCoroutine()) {
      $this->createConnection();
      return;
    }
    if ($this->connectionCount >= $this->max_size) return;
    $context = Coroutine::getContext();
    $depth = (int)($context[self::MAKE_DEPTH_CONTEXT_KEY] ?? 0);
    if ($depth >= self::MAX_MAKE_DEPTH) {
      throw new ConnectionPoolException(
        '创建连接失败: 已达到最大重试次数(' . self::MAX_MAKE_DEPTH . '次), 请检查 createConnection() 与 connectionDetection() 实现'
      );
    }
    $context[self::MAKE_DEPTH_CONTEXT_KEY] = $depth + 1;
    // 先占位再建连：检查与自增之间无协程切换点，等价原子"检查并占位"。
    // 若在 createConnection() 之后才自增，多个协程可同时通过容量检查，
    // 各自建连后总连接数超发，超出的 push 在满容量 Channel 上永久阻塞（协程死锁）
    $this->connectionCount++;
    try {
      $connection = $this->createConnection();
      // 直接入池（不经 put 的健康检测：新连接由 pop 侧检测兜底），
      // 避免 put 检测失败分支再次 make 造成计数与递归双重复杂化。
      // push 带兜底超时：容量计数与队列长度理论上已一致，此处仅防御性兜底，
      // 失败时关闭连接并回退计数，避免任何路径演变为永久阻塞
      if (!$this->pool->push($connection, self::CHANNEL_FALLBACK_TIMEOUT)) {
        $this->closeConnection($connection);
        $this->connectionCount--;
      }
    } catch (Throwable $e) {
      // 建连失败必须回退占位计数，否则容量被虚占、池永久缺连接
      $this->connectionCount--;
      throw $e;
    } finally {
      // finally 保证异常路径也正确还原进入前的深度
      $context[self::MAKE_DEPTH_CONTEXT_KEY] = $depth;
    }
  }

  /**
   * 创建一个新连接，子类必须实现
   *
   * @return mixed 可用的连接对象
   */
  abstract protected function createConnection(): mixed;

  /**
   * 关闭一个连接，子类必须实现
   *
   * 非协程环境下连接无法归还到协程通道，put() 时调用此方法显式释放连接，
   * 避免长循环脚本（如全量导入）积压连接耗尽数据库连接数（1040）。
   *
   * @param mixed $connection 待关闭的连接
   */
  abstract protected function closeConnection(mixed $connection): void;

  /**
   * @inheritDoc
   */
  #[Override]
  public function isFull(): bool
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    return $this->pool->isFull();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function put(mixed $connection): void
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    // 非协程环境连接无法归还到协程通道，若直接丢弃会导致连接永不释放，
    // 长循环脚本（如全量导入）会积压连接最终打爆数据库连接数（1040），因此改为显式关闭
    if (!$this->isCoroutine()) {
      $this->closeConnection($connection);
      return;
    }
    // 判断返回连接是否为NULL 和 连接是否可用 可用则归还连接
    if ($connection !== null && $this->connectionDetection($connection)) {
      // 归还的连接本就计入 connectionCount，队列容量理论上必然足够；
      // push 仍带兜底超时而非永久等待，防御计数与队列瞬时错配导致协程死锁
      $result = $this->pool->push($connection, self::CHANNEL_FALLBACK_TIMEOUT);
      if ($result === false) throw new ConnectionPoolException(
        self::ERROR_MESSAGE[$this->pool->errCode],
        $this->pool->errCode
      );
    } else {
      // 不可用连接：显式关闭并核减总连接数（原实现直接丢弃导致计数泄漏、
      // 底层连接未关闭），再按容量缺口补建新连接填补
      if ($connection !== null) $this->closeConnection($connection);
      $this->connectionCount--;
      $this->make();
    }
  }

  /**
   * 检测连接是否可用，子类必须实现，在借出和归还时调用
   *
   * @param mixed $connection 待检测的连接
   * @return bool 可用返回 true
   */
  abstract protected function connectionDetection(mixed $connection): bool;

  /**
   * @inheritDoc
   */
  #[Override]
  public function get(float $timeout = -1): mixed
  {
    return $this->pop($timeout);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function pop(float $timeout = -1): mixed
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    // 如果非协程环境 则直接创建连接
    if (!$this->isCoroutine()) return $this->createConnection();
    // 非阻塞获取池内闲置连接：
    // 不可用 pop(0) —— Swoole 6.x 中超时 0 与 -1 同为"永久等待"，空池 pop(0) 会挂死协程。
    // 改用 length() 预检：协程非抢占式调度，检查与 pop 之间无切换点，
    // length>0 时 pop(-1) 必然立即取得元素，等价于安全的非阻塞读
    $connection = $this->pool->length() > 0 ? $this->pool->pop(-1) : false;
    if ($connection === false) {
      // 池空：未达容量则新建（按总连接数判定，杜绝借出期无限新建）
      if ($this->connectionCount < $this->max_size) {
        $this->make();
        // make 后可能有并发协程抢先取走刚入池的连接，不可无限期等待，
        // 统一按调用方超时等待（含 make 刚入池立即可得的正常路径）
        $connection = $this->pool->pop($timeout >= 0 ? $timeout : self::CHANNEL_FALLBACK_TIMEOUT);
      } else {
        // 已达容量：阻塞等待其他协程归还，而非继续新建突破容量
        $connection = $this->pool->pop($timeout >= 0 ? $timeout : self::CHANNEL_FALLBACK_TIMEOUT);
      }
    }
    if ($connection === false) throw new ConnectionPoolException(
      self::ERROR_MESSAGE[$this->pool->errCode],
      $this->pool->errCode
    );
    //判断连接是否可用 如果连接不可用则返回一个新的连接 不可用的连接将会被丢弃
    // 修复: 对 createConnection() 的新连接也执行 connectionDetection 检测，避免返回不可用连接
    if (!$this->connectionDetection($connection)) {
      $this->closeConnection($connection);
      $this->connectionCount--;
      $connection = $this->createConnection();
      $this->connectionCount++;
      if (!$this->connectionDetection($connection)) {
        $this->connectionCount--;
        $this->closeConnection($connection);
        throw new RuntimeException('新创建的连接不可用');
      }
    }
    return $connection;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function length(): int
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    return $this->pool->length();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function isEmpty(): bool
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    return $this->pool->isEmpty();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function stats(): array
  {
    // 修复: close() 后 $this->pool 为 null，调用方法会触发 Fatal Error
    if ($this->pool === null) {
      throw new RuntimeException('连接池已关闭');
    }
    return $this->pool->stats();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function close(): bool
  {
    $result = $this->pool->close();
    // 修复: 不能使用 unset() 销毁类型属性, 否则属性会变为"未初始化"状态,
    // 后续访问会抛出 Error: Typed property must not be accessed before initialization
    // 改为将属性设为 null (属性已声明为可空类型 ?Channel)
    if ($result) $this->pool = null;
    return $result;
  }
}
