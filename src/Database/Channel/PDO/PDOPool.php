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

namespace Viswoole\Database\Channel\PDO;

use Exception;
use InvalidArgumentException;
use Override;
use PDO;
use PDO\Mysql;
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
  #[Override]
  public function getConfig(): PDOConfig
  {
    return $this->PDOConfig;
  }

  /**
   * 获取数据库连接
   *
   * @param float $timeout 超时时间
   * @return PDOProxy
   */
  #[Override]
  public function get(float $timeout = -1): PDOProxy
  {
    return parent::get($timeout);
  }

  /**
   * 获取数据库连接
   *
   * @inheritDoc
   */
  public function pop(float $timeout = -1): PDOProxy
  {
    return parent::pop($timeout);
  }

  /**
   * 创建新的 PDO 代理连接
   *
   * MySQL 连接按 timezone 配置注入连接时区（SET time_zone）：
   * 命名时区统一转为 UTC 偏移（服务端时区表加载情况不可靠），
   * 经 MYSQL_ATTR_INIT_COMMAND 在建连时执行；用户已设置 INIT_COMMAND
   * 时追加而非覆盖。PG 经 DSN options 注入（见 createDSN）。
   *
   * @return PDOProxy 新建的 PDO 代理连接
   * @throws Exception 驱动不支持时抛出
   */
  #[Override]
  protected function createConnection(): PDOProxy
  {
    $options = ['dsn' => $this->createDSN($this->PDOConfig->type)];
    if ($this->PDOConfig->type !== DriverType::SQLite) {
      $options['username'] = $this->PDOConfig->username;
      $options['password'] = $this->PDOConfig->password;
      $options['options'] = $this->PDOConfig->options;
      if ($this->PDOConfig->type === DriverType::MYSQL) {
        $this->applyMysqlTimezone($options['options']);
        $this->assertBufferedQuery($options['options']);
      }
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
        // PG 原生支持命名时区，经 DSN options 每连接生效（对齐应用/配置时区）
        $timezone = $this->PDOConfig->resolveTimezone();
        if ($timezone !== null) {
          $dsn .= ";options='-c TimeZone=" . str_replace("'", "''", $timezone) . "'";
        }
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
   * 拒绝非缓冲查询模式（MYSQL_ATTR_USE_BUFFERED_QUERY = false）
   *
   * 框架的连接归还契约是"execute 在 finally 中先归还连接，调用方之后才 fetch"：
   * 缓冲模式下结果集已由 mysqlnd 拉至客户端内存，归还后 fetch 为纯本地操作；
   * 非缓冲模式下归还时服务端游标仍未关闭，其他协程借到同连接会触发
   * Commands out of sync 协议错乱——该选项与连接池根本不兼容，入口直接拒绝
   *
   * @param array $options 用户配置的 PDO 选项
   * @throws InvalidArgumentException 配置了非缓冲查询时抛出
   */
  private function assertBufferedQuery(array $options): void
  {
    // PHP 8.5 起 PDO::MYSQL_ATTR_USE_BUFFERED_QUERY 弃用，改用驱动常量
    $key = Mysql::ATTR_USE_BUFFERED_QUERY;
    // 宽松真值判定而非 === false：0 等 falsy 值传给 PDO 同样等效关闭缓冲，
    // 严格比较会被其绕过校验
    if (array_key_exists($key, $options) && !$options[$key]) {
      throw new InvalidArgumentException(
        '不支持 PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false：非缓冲查询与连接池的连接归还契约不兼容，请使用默认缓冲模式'
      );
    }
  }

  /**
   * 在 MySQL 连接选项中注入连接时区语句
   *
   * 空数组 options 不修改原配置：$options 为值拷贝（数组传值），
   * 且 INIT_COMMAND 追加语义要求保留用户已有语句。
   *
   * @param array $options PDO 选项（引用修改，含用户已配置项）
   */
  private function applyMysqlTimezone(array &$options): void
  {
    $timezone = $this->PDOConfig->resolveTimezone();
    if ($timezone === null) return;
    // PHP 8.5 起 PDO::MYSQL_ATTR_INIT_COMMAND 弃用，改用驱动常量
    $initCommand = Mysql::ATTR_INIT_COMMAND;
    if (isset($options[$initCommand])) {
      // 用户已设置 INIT_COMMAND：其中已含时区语句（如自行 SET time_zone）时
      // 尊重用户设置不再追加，否则以分号追加时区语句在后
      if (stripos((string)$options[$initCommand], 'set time_zone') !== false) return;
      $options[$initCommand] .= ";SET time_zone = '" . PDOConfig::toMysqlOffset($timezone) . "'";
    } else {
      $options[$initCommand] = "SET time_zone = '" . PDOConfig::toMysqlOffset($timezone) . "'";
    }
  }

  /**
   * 通过执行 SELECT 1 检测连接是否仍然可用
   *
   * @param PDO|PDOProxy $connection 待检测的连接
   * @return bool 连接可用返回 true
   */
  #[Override]
  protected function connectionDetection(mixed $connection): bool
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
  #[Override]
  protected function closeConnection(mixed $connection): void
  {
    if ($connection instanceof PDOProxy) {
      $connection->close();
    }
  }
}
