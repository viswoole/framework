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

namespace Viswoole\Database\Driver\PDO;

use Exception;
use Override;
use PDO;
use Throwable;
use Viswoole\Database\Driver\ConnectionDriver;

/**
 * PDO连接池
 */
class Pool extends ConnectionDriver
{

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
   * 创建连接
   *
   * @return PDOProxy
   * @throws Exception
   */
  #[Override] protected function createConnection(): PDOProxy
  {
    $options = ['dns' => $this->createDSN($this->PDOConfig->type)];
    if ($this->PDOConfig->type !== PDODriver::SQLite) {
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
  private function createDSN(PDODriver $driver): string
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
        $dsn = 'oci:dbname=' . ($this->PDOConfig->unixSocket ?: $this->PDOConfig->host) . ':' . $this->PDOConfig->port . '/' . $this->PDOConfig->database . ';charset=' . $this->PDOConfig->charset;
        break;
      case 'sqlite':
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
