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

use Override;
use PDO;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Channel;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Manager\Connect;

/**
 * PDO通道
 */
class PDOChannel extends Channel
{
  /**
   * @var PDOPool|array{read:PDOPool,write:PDOPool} 连接池
   */
  private readonly PDOPool|array $pool;

  /**
   * @param PDODriverType $type 数据库类型
   * @param string|array{read:string,write:string} $host 链接地址，支持读写分离
   * @param int $port 端口
   * @param string|null $unixSocket unixSocket
   * @param string $database 数据库名称
   * @param string $table_prefix 表前缀
   * @param string $username 用户名
   * @param string $password 密码
   * @param string $charset 数据库编码
   * @param array $options 其他配置
   * @param int $pool_max_size 连接池最大长度
   * @param int $pool_fill_size 连接池默认填充长度，默认0为不填充
   * @param int $pool_timeout_time 获取或归还连接超时时间，默认5秒
   */
  public function __construct(
    public readonly PDODriverType $type = PDODriverType::MYSQL,
    string|array                  $host = '127.0.0.1',
    int                           $port = 3306,
    ?string                       $unixSocket = null,
    string                        $database = 'test',
    public readonly string        $table_prefix = '',
    string                        $username = 'root',
    string                        $password = 'root',
    string                        $charset = 'utf8mb4',
    array                         $options = [
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ],
    int                           $pool_max_size = 64,
    int                           $pool_fill_size = 0,
    public int                    $pool_timeout_time = 5,
  )
  {
    $config = compact(
      'type', 'host', 'port', 'unixSocket', 'database', 'username', 'password',
      'charset', 'options', 'pool_max_size', 'pool_fill_size'
    );
    if (is_array($host)) {
      $read = new PDOConfig(...array_merge($config, ['host' => $host['read'], 'onlyRead' => true]));
      $write = new PDOConfig(...array_merge($config, ['host' => $host['write']]));
      $this->pool = [
        'read' => new PDOPool($read),
        'write' => new PDOPool($write)
      ];
    } else {
      $this->pool = new PDOPool(new PDOConfig(...$config));
    }
  }

  /**
   * 查询
   *
   * @param string $sql
   * @param array $params
   * @return array
   */
  #[Override] public function query(string $sql, array $params = []): array
  {
    if ($this->getType($sql) === 'write') throw new DbException('Read-only operation');
    $connect = Connect::factory()->pop($this, 'read');
    $stmt = $this->exec($connect, $sql, $params);
    $result = $stmt->fetchAll();
    Connect::factory()->put($this, $connect);
    if (false === $result) {
      throw new DbException('fetch dataset failed', sql: self::sqlMergeParams($sql, $params));
    }
    return $result;
  }

  /**
   * 是否为写操作
   *
   * @param string $sql
   * @return string
   */
  protected function getType(string $sql): string
  {
    return str_starts_with(strtoupper($sql), 'SELECT') ? 'read' : 'write';
  }

  /**
   * 获取连接
   *
   * @param string $type
   * @return PDOProxy
   */
  #[Override] public function pop(string $type = 'write'): PDOProxy
  {
    return $this->getPool($type)->pop($this->pool_timeout_time);
  }

  /**
   * 通过sql获取连接池，读写分离
   *
   * @param string $type
   * @return PDOPool
   */
  protected function getPool(string $type): PDOPool
  {
    if (is_array($this->pool)) {
      $pool = $this->pool[$type];
    } else {
      $pool = $this->pool;
    }
    return $pool;
  }

  /**
   * 原生查询
   *
   * @param PDOProxy $connect
   * @param string $sql
   * @param array $params
   * @return PDOStatementProxy|PDOStatement
   */
  protected function exec(
    PDOProxy $connect,
    string   $sql,
    array    $params = [],
  ): PDOStatementProxy|PDOStatement
  {
    $stmt = $connect->prepare($sql);
    if ($stmt === false) {
      $err = $connect->errorInfo();
      throw new DbException($err[2], $err[1], self::sqlMergeParams($sql, $params));
    }
    $result = $stmt->execute($params);
    if ($result === false) {
      $err = $connect->errorInfo();
      throw new DbException($err[2], $err[1], self::sqlMergeParams($sql, $params));
    }
    return $stmt;
  }

  /**
   * 合并参数
   *
   * @param string $sql
   * @param array $params
   * @return string
   */
  public static function sqlMergeParams(string $sql, array $params): string
  {
    if (!empty($params)) {
      // 替换参数值
      $patterns = array_map(function ($param) {
        return '/(?<!\w):' . preg_quote($param, '/') . '(?!\w)/';
      }, array_keys($params));

      $sql = preg_replace_callback($patterns, function ($matches) use ($params) {
        $paramKey = ltrim($matches[0], ':');
        $paramValue = $params[$paramKey];
        if ($paramValue === null) return 'NULL';
        return is_string($paramValue) ? "'" . addslashes($paramValue) . "'" : $paramValue;
      }, $sql);
    }
    return $sql;
  }

  /**
   * 写入
   *
   * @param string $sql
   * @param array $params
   * @param bool $getId
   * @return int|string
   */
  #[Override] public function execute(
    string $sql,
    array  $params = [],
    bool   $getId = false,
  ): int|string
  {
    /**
     * @var PDOProxy $connect
     */
    $connect = Connect::factory()->pop($this, 'write');
    $stmt = $this->exec($connect, $sql, $params);
    if ($getId) {
      $result = $connect->lastInsertId();
    } else {
      $result = $stmt->rowCount();
    }
    Connect::factory()->put($this, $connect);
    return $result;
  }

  /**
   * 归还连接
   *
   * @param mixed $connect
   * @return void
   */
  #[Override] public function put(mixed $connect): void
  {
    if ($connect instanceof PDOProxy) {
      if ($connect->onlyRead) {
        $this->getPool('read')->put($connect);
      } else {
        $this->getPool('write')->put($connect);
      }
    }
  }
}
