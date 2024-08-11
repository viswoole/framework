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

use InvalidArgumentException;
use Override;
use PDO;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Collector\CrudMethod;
use Viswoole\Database\Collector\QueryOptions;
use Viswoole\Database\Driver;
use Viswoole\Database\Exception\DbException;

/**
 * PDO通道
 */
class PDOChannel implements Driver\Contract\ChannelInterface
{
  /**
   * @var PDOPool|array{read:PDOPool,write:PDOPool} 连接池
   */
  private readonly PDOPool|array $pool;

  /**
   * @param PDODriverType $type 数据库类型
   * @param string|array{read:string,write:string} $host 链接地址
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
    public readonly int           $pool_timeout_time = 5,
  )
  {
    $config = compact(
      'type', 'host', 'port', 'unixSocket', 'database', 'username', 'password',
      'charset', 'options', 'pool_max_size', 'pool_fill_size'
    );
    if (is_array($host)) {
      $read = new PDOConfig(...array_merge($config, ['host' => $host['read']]));
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
   * 原生查询
   *
   * @param string $sql
   * @param array $params
   * @return mixed 查询成功返回数据集
   * @throws DbException 查询失败抛出异常
   */
  #[Override] public function query(string $sql, array $params = []): mixed
  {
    if (!$this->isQuery($sql)) {
      throw new InvalidArgumentException("query methods only support queries $sql invalid");
    }
    $stmt = $this->exec($sql, $params);
  }

  /**
   * 获取sql类型
   *
   * @param string $sql
   * @return bool
   */
  private function isQuery(string $sql): bool
  {
    $sql = strtoupper(trim($sql));
    if (str_starts_with($sql, 'SELECT')) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * 原生执行sql
   *
   * @param string $sql
   * @param array $params
   * @return PDOStatementProxy|PDOStatement 返回原生的执行结果
   */
  #[Override] public function exec(string $sql, array $params = []): PDOStatementProxy|PDOStatement
  {
    $pool = $this->getPool($sql);
    $pdo = $pool->get($this->pool_timeout_time);
  }

  /**
   * 通过sql获取连接池，读写分离
   *
   * @param string $sql
   * @return PDOPool
   */
  protected function getPool(string $sql): PDOPool
  {
    if (is_array($this->pool)) {
      $isQuery = $this->isQuery($sql); 
      $pool = $isQuery ? $this->pool['read'] : $this->pool['write'];
    } else {
      $pool = $this->pool;
    }
    return $pool;
  }

  /**
   * 原生写入
   *
   * @param string $sql 要执行的查询语句
   * @param array $params 要绑定的参数
   * @param bool $getLastInsertId 是否返回最后插入的ID
   * @return int|string 查询成功返回受影响的行数
   * @throws DbException 查询失败抛出异常
   */
  #[Override] public function execute(
    string $sql, array $params = [], bool $getLastInsertId = false
  ): int|string
  {
    // TODO: Implement execute() method.
  }

  /**
   * 构建sql
   *
   * @param QueryOptions $options 查询选项
   * @param CrudMethod $crud crud方法
   * @param bool $merge 是否将参数和sql语句合并，不使用占位符
   * @return string|array{sql:string,params:array<string,mixed>}
   */
  #[Override] public function build(
    QueryOptions $options,
    bool         $merge = true,
  ): string|array
  {
    // TODO: Implement build() method.
  }
}
