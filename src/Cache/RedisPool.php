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

namespace Viswoole\Cache;

use Override;
use Redis;
use RedisException;
use Throwable;
use Viswoole\Core\Channel\ConnectionPool;

/**
 * Redis 协程连接池，基于 Swoole Channel 实现连接的借出与归还
 *
 * 继承通用连接池，提供 Redis 连接的创建、健康检测与生命周期管理。
 * 在 Swoole 协程环境下确保每个协程安全地获取和释放 Redis 连接。
 *
 * @see ConnectionPool
 * @see RedisConfig
 */
class RedisPool extends ConnectionPool
{
  /**
   * 初始化连接池并设置容量参数
   *
   * @param RedisConfig $config Redis 连接配置，包含连接参数与池容量
   */
  public function __construct(protected RedisConfig $config)
  {
    parent::__construct($config->pool_max_size, $config->pool_fill_size);
  }

  /**
   * 获取当前连接池的 Redis 配置
   *
   * @return RedisConfig Redis 连接配置实例
   */
  #[Override] public function getConfig(): RedisConfig
  {
    return $this->config;
  }

  /**
   * 创建新的 Redis 连接并完成认证与数据库选择
   *
   * @return Redis 已认证并选择数据库的 Redis 连接实例
   * @throws RedisException 连接、认证或选择数据库失败时抛出
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
   * 检测连接是否存活，通过 PING 命令验证
   *
   * @param mixed $connection 待检测的连接实例
   * @return bool 连接存活返回 true
   */
  #[Override] protected function connectionDetection(mixed $connection): bool
  {
    if (!($connection instanceof Redis)) return false;
    try {
      $result = $connection->ping();
    } catch (Throwable) {
      return false;
    }
    // 修复：ping() 实际返回字符串 '+PONG' 或 true，与方法声明的 bool 返回类型冲突，统一转为布尔值
    return $result === true || $result === '+PONG';
  }

  /**
   * 关闭 Redis 连接
   *
   * 非协程环境下连接无法归还到连接池，put() 时调用此方法释放连接。
   *
   * @param Redis $connection 待关闭的连接
   */
  #[Override] protected function closeConnection(mixed $connection): void
  {
    if ($connection instanceof Redis) {
      $connection->close();
    }
  }
}
