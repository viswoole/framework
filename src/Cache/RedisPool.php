<?php
/*
 *  +----------------------------------------------------------------------
 *  | ViSwoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Cache;

use Override;
use Redis;
use RedisException;
use Throwable;
use ViSwoole\Core\Channel\ConnectionPool;

/**
 * Redis连接池
 */
class RedisPool extends ConnectionPool
{
  public function __construct(protected RedisConfig $config)
  {
    parent::__construct($config->pool_max_size, $config->pool_fill_size);
  }

  /**
   * @inheritDoc
   * @return RedisConfig
   */
  #[Override] public function getConfig(): RedisConfig
  {
    return $this->config;
  }

  /**
   * 必须实现创建连接方法
   *
   * @return Redis 返回一个可用的连接对象
   * @throws RedisException
   */
  #[Override] protected function createConnection(): Redis
  {
    $redis = new Redis();
    $redis->connect(
      $this->config->host,
      $this->config->port,
      $this->config->timeout,
      null,
      $this->config->retry_interval,
      $this->config->read_timeout
    );
    if (!empty($this->config->password)) {
      $result = $redis->auth($this->config->password);
      if (true !== $result) throw new RedisException('Redis auth fail');
    }
    if ($this->config->db_index !== 0) {
      $result = $redis->select($this->config->db_index);
      if (true !== $result) throw new RedisException('Redis select db fail');
    }
    return $redis;
  }

  /**
   * 可实现此方法在获取或归还连接时检测连接是否可用
   *
   * @param mixed $connection
   * @return bool 如果返回true则代表连接可用
   */
  #[Override] protected function connectionDetection(mixed $connection): bool
  {
    if (!($connection instanceof Redis)) return false;
    try {
      $result = $connection->ping();
    } catch (Throwable) {
      return false;
    }
    return $result;
  }
}
