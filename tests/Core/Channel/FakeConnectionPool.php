<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core\Channel;

use Override;
use Viswoole\Core\Channel\ConnectionPool;

/**
 * 连接池测试替身
 *
 * 提供可控的连接创建、可用性检测与关闭记录，
 * 用于验证 ConnectionPool 基类在协程/非协程环境下的连接生命周期逻辑。
 */
class FakeConnectionPool extends ConnectionPool
{
  /** @var int createConnection() 的调用次数 */
  public int $createdCount = 0;
  /** @var array<int,mixed> closeConnection() 接收到的连接列表 */
  public array $closed = [];

  /**
   * 供测试直接调用受保护的 createConnection()
   *
   * @return mixed 新建的连接
   */
  public function createForTest(): mixed
  {
    return $this->createConnection();
  }

  /**
   * 记录创建次数并返回一个简单连接对象
   */
  #[Override]
  protected function createConnection(): mixed
  {
    $this->createdCount++;
    return (object)['id' => $this->createdCount];
  }

  /**
   * 非空即视为可用
   */
  #[Override]
  protected function connectionDetection(mixed $connection): bool
  {
    return $connection !== null;
  }

  /**
   * 记录被关闭的连接
   */
  #[Override]
  protected function closeConnection(mixed $connection): void
  {
    $this->closed[] = $connection;
  }

  /**
   * 无实际配置，返回 null
   */
  #[Override]
  public function getConfig(): mixed
  {
    return null;
  }
}
