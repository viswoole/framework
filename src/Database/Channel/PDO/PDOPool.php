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

namespace Viswoole\Database\Channel\PDO;

use Exception;
use Override;
use PDO;
use Throwable;
use Viswoole\Core\Channel\ConnectionPool;

/**
 * PDO 连接池
 *
 * 基于 Swoole 协程连接池管理 PDO 连接的生命周期，
 * 支持连接健康检测与自动重建。
 */
class PDOPool extends ConnectionPool
{

  /**
   * @param PDOConfig $PDOConfig 连接配置，同时包含池容量参数
   */
  public function __construct(protected PDOConfig $PDOConfig)
  {
    parent::__construct($PDOConfig->pool_max_size, $PDOConfig->pool_fill_size);
  }

  /**
   * 获取当前连接配置
   *
   * @return PDOConfig 连接配置实例
   */
  #[Override] public function getConfig(): PDOConfig
  {
    return $this->PDOConfig;
  }

  /**
   * 获取数据库连接
   *
   * @param float $timeout 超时时间
   * @return PDOProxy
   */
  #[Override] public function get(float $timeout = -1): PDOProxy
  {
    return parent::get($timeout);
  }

  /**
   * 获取数据库连接
   *
   * @param float $timeout 超时时间
   * @return PDOProxy
   */
  public function pop(float $timeout = -1): PDOProxy
  {
    return parent::pop($timeout);
  }

  /**
   * 创建新的 PDO 代理连接
   *
   * @return PDOProxy 新建的 PDO 代理连接
   * @throws Exception 驱动不支持时抛出
   */
  #[Override] protected function createConnection(): PDOProxy
  {
    $options = ['dsn' => $this->createDSN($this->PDOConfig->type)];
    if ($this->PDOConfig->type !== DriverType::SQLite) {
      $options['username'] = $this->PDOConfig->username;
      $options['password'] = $this->PDOConfig->password;
      $options['options'] = $this->PDOConfig->options;
    }
    return new PDOProxy(...$options);
  }

  /**
   * create DSN
   * @throws Exception
   */
  private function createDSN(DriverType $driver): string
  {
    switch ($driver->value) {
      case 'mysql':
        if ($this->PDOConfig->unixSocket) {
          $dsn = "mysql:unix_socket={$this->PDOConfig->unixSocket};dbname={$this->PDOConfig->database};charset={$this->PDOConfig->charset}";
        } else {
          $dsn = "mysql:host={$this->PDOConfig->host};port={$this->PDOConfig->port};dbname={$this->PDOConfig->database};charset={$this->PDOConfig->charset}";
        }
        break;
      case 'pgsql':
        $dsn = 'pgsql:host=' . ($this->PDOConfig->unixSocket ?: $this->PDOConfig->host) . ";port={$this->PDOConfig->port};dbname={$this->PDOConfig->database}";
        break;
      case 'oci':
        $host = $this->PDOConfig->unixSocket ?: $this->PDOConfig->host;
        $dsn = 'oci:dbname=' . $host . ':' . $this->PDOConfig->port . '/' . $this->PDOConfig->database . ';charset=' . $this->PDOConfig->charset;
        break;
      case 'sqlsrv':
        $host = $this->PDOConfig->host . ':' . $this->PDOConfig->port;
        $dsn = 'sqlsrv:Server=' . $host . ';Database=' . $this->PDOConfig->database;
        break;
      case 'sqlite':
        // There are three types of SQLite databases: databases on disk, databases in memory, and temporary
        // databases (which are deleted when the connections are closed). It doesn't make sense to use
        // connection pool for the latter two types of databases, because each connection connects to a
        //different in-memory or temporary SQLite database.
        if ($this->PDOConfig->database === '') {
          throw new Exception(
            'Connection pool in Swoole does not support temporary SQLite databases.'
          );
        }
        if ($this->PDOConfig->database === ':memory:') {
          throw new Exception(
            'Connection pool in Swoole does not support creating SQLite databases in memory.'
          );
        }
        $dsn = 'sqlite:' . $this->PDOConfig->database;
        break;
      default:
        throw new Exception('Unsupported Database Driver:' . $driver->value);
    }
    return $dsn;
  }

  /**
   * 通过执行 SELECT 1 检测连接是否仍然可用
   *
   * @param PDO|PDOProxy $connection 待检测的连接
   * @return bool 连接可用返回 true
   */
  #[Override] protected function connectionDetection(mixed $connection): bool
  {
    try {
      $connection->query('SELECT 1');
    } catch (Throwable) {
      return false;
    }
    return true;
  }

  /**
   * 关闭 PDO 连接
   *
   * 非协程环境下连接无法归还到连接池，put() 时调用此方法释放底层连接。
   *
   * @param PDO|PDOProxy $connection 待关闭的连接
   */
  #[Override] protected function closeConnection(mixed $connection): void
  {
    if ($connection instanceof PDOProxy) {
      $connection->close();
    }
  }
}
