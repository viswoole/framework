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

declare (strict_types=1);

namespace Viswoole\Core\Channel;

use Override;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Viswoole\Core\Channel\Contract\ConnectionPoolInterface;
use Viswoole\Core\Exception\ConnectionPoolException;
use Viswoole\Core\Server\ServerEventHook;

/**
 * Abstract ConnectionPool.
 * 连接池抽象类，基于\Swoole\Coroutine\Channel通道实现了连接池的基本功能
 */
abstract class ConnectionPool implements ConnectionPoolInterface
{
  public const int DEFAULT_SIZE = 64;
  public const array ERROR_MESSAGE = [
    SWOOLE_CHANNEL_OK => '正常',
    SWOOLE_CHANNEL_TIMEOUT => '失败：-1 连接超时',
    SWOOLE_CHANNEL_CLOSED => '失败：-2 连接已关闭',
    SWOOLE_CHANNEL_CANCELED => '失败：-3 意外取消'
  ];
  /** @var Channel 当前连接池 */
  protected Channel $pool;

  /**
   * @param int $max_size 连接池长度
   * @param int|null $default_fill 默认填充长度,null为不填充
   */
  public function __construct(
    protected int $max_size = self::DEFAULT_SIZE,
    ?int          $default_fill = null
  )
  {
    $this->pool = new Channel($max_size);
    if ($default_fill) {
      // hook服务启动事件，填充连接池
      ServerEventHook::addEvent('start', function () use ($default_fill) {
        $this->fill($default_fill);
      });
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function fill(int $size = null): void
  {
    if (!$this->isCoroutine()) return;
    $size = $size === null ? $this->max_size : $size;
    while ($size > $this->length()) {
      $this->make();
    }
  }

  /**
   * 判断是否在协程环境
   *
   * @return bool
   */
  private function isCoroutine(): bool
  {
    return Coroutine::getCid() > -1;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function length(): int
  {
    return $this->pool->length();
  }

  /**
   * 往连接池中新增一个连接
   *
   * @return void
   */
  protected function make(): void
  {
    $connection = $this->createConnection();
    if (!$this->isCoroutine()) return;
    $this->put($connection);
  }

  /**
   * 必须实现创建连接方法
   *
   * @return mixed 返回一个可用的连接对象
   */
  abstract protected function createConnection(): mixed;

  /**
   * @inheritDoc
   */
  #[Override] public function put(mixed $connection): void
  {
    // 非协程环境不归还连接
    if (!$this->isCoroutine()) return;
    // 判断返回连接是否为NULL 和 连接是否可用 可用则归还连接
    if ($connection !== null && $this->connectionDetection($connection)) {
      $result = $this->pool->push($connection);
      if ($result === false) throw new ConnectionPoolException(
        self::ERROR_MESSAGE[$this->pool->errCode],
        $this->pool->errCode
      );
    } else {
      // 如果归还的是空连接或不可用则需要重新创建一个新连接填补
      $this->make();
    }
  }

  /**
   * 可实现此方法在获取或归还连接时检测连接是否可用
   *
   * @param mixed $connection
   * @return bool 如果返回true则代表连接可用
   */
  abstract protected function connectionDetection(mixed $connection): bool;

  /**
   * @inheritDoc
   */
  #[Override] public function get(float $timeout = -1): mixed
  {
    return $this->pop($timeout);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function pop(float $timeout = -1): mixed
  {
    // 如果非协程环境 则直接创建连接
    if (!$this->isCoroutine()) return $this->createConnection();
    if ($this->isEmpty() && $this->length() < $this->max_size) $this->make();
    // 获取连接
    $connection = $this->pool->pop($timeout);
    if ($connection === false) throw new ConnectionPoolException(
      self::ERROR_MESSAGE[$this->pool->errCode],
      $this->pool->errCode
    );
    //判断连接是否可用 如果连接不可用则返回一个新的连接 不可用的连接将会被丢弃
    if (!$this->connectionDetection($connection)) $connection = $this->createConnection();
    return $connection;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function isEmpty(): bool
  {
    return $this->pool->isEmpty();
  }

  /**
   * @inheritDoc
   */
  #[Override] public function isFull(): bool
  {
    return $this->pool->isFull();
  }

  /**
   * @inheritDoc
   */
  #[Override] public function stats(): array
  {
    return $this->pool->stats();
  }

  /**
   * @inheritDoc
   */
  #[Override] public function close(): bool
  {
    $result = $this->pool->close();
    if ($result) unset($this->pool);
    return $result;
  }
}
