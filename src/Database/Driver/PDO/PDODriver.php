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

use Override;
use PDO;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Collector\QueryOptions;
use Viswoole\Database\DataSet\DataSetCollection;
use Viswoole\Database\DataSet\Row;
use Viswoole\Database\Driver;
use Viswoole\Database\Driver\Contract\DriverInterface;

class PDODriver implements DriverInterface
{
  /**
   * @var Pool|array{read:Pool,write:Pool} 连接池
   */
  private readonly Pool|array $pool;

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
    int                           $pool_fill_size = 0
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
        'read' => new Pool($read),
        'write' => new Pool($write)
      ];
    } else {
      $this->pool = new Pool(new PDOConfig(...$config));
    }
  }

  /**
   * 数据库表统一前缀
   *
   * @param string|null $table 表名
   * @return string 如果传入表名则返回表前缀+表名 否则返回表前缀
   */
  #[Override] public function prefix(string $table = null): string
  {
    return is_null($table) ? $this->table_prefix : $this->table_prefix . trim($table);
  }

  /**
   * 打包sql
   *
   * @param QueryOptions $options 查询构造器选项
   * @return string|array 如果合并sql则返回字符串 否则返回['sql'=>string,'params'=>array]
   */
  #[Override] public function builder(QueryOptions $options): string|array
  {
    $builder = new SqlBuilder($this, $options);
    $sql = $builder->build($options->getSql === 2);
    return '';
  }

  /**
   * 原生查询
   *
   * @param string $sql
   * @param array $params
   * @return DataSetCollection|Row
   */
  #[Override] public function query(string $sql, array $params = []): DataSetCollection|Row
  {
    // TODO: Implement query() method.
  }

  /**
   * 原生写入
   *
   * @param string $sql
   * @param array $params
   * @return int
   */
  #[Override] public function execute(string $sql, array $params = []): int
  {
    // TODO: Implement execute() method.
  }

  /**
   * 原生执行sql
   *
   * @param string $sql
   * @param array $params
   * @return PDOStatementProxy|PDOStatement 返回原生的执行结果，例如PDO驱动则返回PDOStatement对象
   */
  #[Override] public function exec(string $sql, array $params = []): mixed
  {
    // TODO: Implement exec() method.
  }
}
