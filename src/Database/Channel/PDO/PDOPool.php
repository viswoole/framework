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
 * PDO连接池
 */
class PDOPool extends ConnectionPool
{

  /**
   * @param PDOConfig $PDOConfig
   */
  public function __construct(protected PDOConfig $PDOConfig)
  {
    parent::__construct($PDOConfig->pool_max_size, $PDOConfig->pool_fill_size);
  }

  /**
   * 获取配置
   *
   * @return PDOConfig
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
   * 创建连接
   *
   * @return PDOProxy
   * @throws Exception
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
   * 检测连接是否可用
   *
   * @param PDO|PDOProxy $connection
   * @return bool
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
}
