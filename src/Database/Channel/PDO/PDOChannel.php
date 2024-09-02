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

use InvalidArgumentException;
use Override;
use PDOException;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Swoole\Table;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\Channel;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * PDO通道
 */
class PDOChannel extends Channel
{
  /**
   * @var PDOPool|array{read:PDOPool[],write:PDOPool[]} 连接池
   */
  private readonly PDOPool|array $pool;
  /**
   * @var Table 全局共享表
   */
  private Table $table;

  /**
   * @param DriverType $type 数据库类型
   * @param string|array{read:array,write:array} $host 链接地址,可以使用unixSocket做为连接地址，支持读写分离
   * @param int $port 端口
   * @param bool $sticky 如果设置为true，则当前请求内，只要写入过数据，其他读操作都会复用该库链接
   * @param string $database 数据库名称
   * @param string $username 用户名
   * @param string $password 密码
   * @param string $charset 数据库编码
   * @param array $options 其他PDO配置
   * @param int $pool_max_size 连接池最大长度
   * @param int $pool_fill_size 连接池默认填充长度，默认0为不填充
   * @param int $pool_timeout_time 获取或归还连接超时时间，默认5秒
   */
  public function __construct(
    public readonly DriverType $type = DriverType::MYSQL,
    string|array               $host = '127.0.0.1',
    int                        $port = 3306,
    public bool                $sticky = true,
    string                     $database = 'test',
    string                     $username = 'root',
    string                     $password = 'root',
    string                     $charset = 'utf8mb4',
    array                      $options = [],
    int                        $pool_max_size = 64,
    int                        $pool_fill_size = 0,
    public int                 $pool_timeout_time = 5,
  )
  {
    $config = compact(
      'type', 'port', 'database', 'username', 'password',
      'charset', 'options', 'pool_max_size', 'pool_fill_size'
    );
    if (empty($host)) throw new InvalidArgumentException('host cannot be empty');
    if (is_array($host)) {
      $reads = is_string($host['read']) ? [$host['read']] : $host['read'];
      array_walk($reads, function (&$item) {
        $item = trim($item);
      });
      $writes = is_string($host['write']) ? [$host['write']] : $host['write'];
      array_walk($writes, function (&$item) {
        $item = trim($item);
      });
      $readPools = $this->createPools($reads, $config);
      $writePools = $this->createPools($writes, $config);
      $this->pool = [
        'read' => $readPools,
        'write' => $writePools
      ];
      $this->table = new Table(1);
      $this->table->column('read', Table::TYPE_INT, 8);
      $this->table->column('write', Table::TYPE_INT, 8);
      $this->table->create();
      $this->table->set('index', ['read' => 0, 'write' => 0]);
    } else {
      $this->pool = $this->createPool(trim($host), $config);
    }
  }

  /**
   * 创建多个连接池
   *
   * @param array $hosts
   * @param array $config
   * @return array
   */
  protected function createPools(array $hosts, array $config): array
  {
    $pools = [];
    foreach ($hosts as $host) {
      $pools[] = $this->createPool($host, $config);
    }
    return $pools;
  }

  /**
   * 创建连接池
   *
   * @param string $host
   * @param array $config
   * @return PDOPool
   */
  protected function createPool(string $host, array $config): PDOPool
  {
    if ($this->isUnixSocketPath($host)) {
      $config['unixSocket'] = $host;
    } else {
      $config['host'] = $host;
    }
    $pdoConfig = new PDOConfig(...$config);
    return new PDOPool($pdoConfig);
  }

  /**
   * 判断是否为unixSocket
   *
   * @param $str
   * @return bool
   */
  protected function isUnixSocketPath($str): bool
  {
    // 检查字符串是否以斜杠开头
    return str_starts_with($str, '/') && str_ends_with($str, '.sock');
  }

  /**
   * 构建sql
   *
   * @param Options $options
   * @return Raw
   */
  #[Override] public function build(Options $options): Raw
  {
    $build = new SqlBuilder($this, $options);
    return $build->build();
  }

  /**
   * @inheritDoc
   */
  public function execute(
    string|Raw $sql,
    array      $bindings = [],
    bool       $getId = false
  ): PDOStatementProxy|PDOStatement|int|string
  {
    $manager = ConnectManager::factory();
    if ($sql instanceof Raw) {
      $bindings = $sql->bindings;
      $sql = $sql->sql;
    }
    /**
     * @var PDOProxy $connect
     */
    $connect = $manager->pop($this, $this->getType($sql));
    try {
      $stmt = $connect->prepare($sql);
      $stmt->execute($bindings);
      if ($getId) {
        if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'REPLACE')) {
          return $connect->lastInsertId();
        }
      }
      // 归还连接
      $manager->put($this, $connect);
    } catch (PDOException $e) {
      // 归还连接
      $manager->put($this, $connect);
      // 抛出异常
      throw new DbException($e->getMessage(), $e->getCode(), Raw::merge($sql, $bindings), $e);
    }
    return $stmt;
  }

  /**
   * 获取连接
   *
   * @param string $type
   * @return PDOProxy
   */
  #[Override] public function pop(string $type): PDOProxy
  {
    $type = strtolower(trim($type)) === 'read' ? 'read' : 'write';
    return $this->getPool($type)->pop($this->pool_timeout_time);
  }

  /**
   * 获取数据库连接池，读写分离
   *
   * @param string $type
   * @return PDOPool
   */
  protected function getPool(string $type): PDOPool
  {
    if (is_array($this->pool)) {
      // 获取上一次写入的连接池索引
      $index = Context::get('$_pdo_write_pool_index');
      // 如果有索引，则继续使用
      if (!is_null($index)) {
        $type = 'write';
        $pools = $this->pool[$type];
      } else {
        $pools = $this->pool[$type];
        // 获取全局共享索引
        $index = $this->table->get('index')[$type];
        // 确保索引在范围内
        $index = $index % count($pools);
        // 如果是写操作并且开启sticky，则通过协程上下文设置索引，在下一次读写操作时，使用相同连接池
        if ($type === 'write' && $this->sticky) {
          Context::set('$_pdo_write_pool_index', $index);
        }
        $this->table->set('index', [$type => $index + 1]);
      }
      // 记录当前协程对应的连接池索引
      $this->setCurrentPoolIndex($type, $index);
      return $pools[$index];
    } else {
      $pool = $this->pool;
    }
    return $pool;
  }

  /**
   * 设置当前协程中获取连接时的索引，归还时使用同一个连接池
   *
   * @param string $type 连接池类型，read|write
   * @param int $index
   * @return void
   */
  private function setCurrentPoolIndex(string $type, int $index): void
  {
    Context::set('$_pdo_current_index', ['type' => $type, 'index' => $index]);
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
   * 归还连接
   *
   * @param PDOProxy $connect
   * @return void
   */
  #[Override] public function put(mixed $connect): void
  {
    if (is_array($this->pool)) {
      $indexInfo = $this->getCurrentPoolIndex();
      $this->pool[$indexInfo['type']][$indexInfo['index']]->put($connect);
      $this->removeCurrentPoolIndex();
    } else {
      $this->pool->put($connect);
    }
  }

  /**
   * 获取当前协程中获取连接时的索引
   *
   * @return array{type:string,index:int}|null
   */
  private function getCurrentPoolIndex(): ?array
  {
    return Context::get('$_pdo_current_index');
  }

  /**
   * 删除当前协程中获取连接时的索引
   *
   * @return void
   */
  private function removeCurrentPoolIndex(): void
  {
    Context::remove('$_pdo_current_index');
  }
}
