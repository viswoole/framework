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

namespace Viswoole\Database;

use Closure;
use InvalidArgumentException;
use Swoole\Table;
use Throwable;
use Viswoole\Core\Config;
use Viswoole\Core\Console\Output;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Query\RunInfo;
use Viswoole\Log\LogManager;

/**
 * 数据库通道管理器
 *
 * @method BaseQuery table(string $table, string $pk = 'id') 选择要查询的表
 * @method array query(string $sql, array $bindings = []) 原生查询 select
 * @method int|string execute(string $sql, array $bindings = []) 原生写入，包括 insert、update、delete
 * @method mixed pop(string $type) 获取可用的连接$type可选值为`read`|`write`
 * @method void put(mixed $connect) 归还一个可用的连接，如果连接已被损坏，请归还null
 */
class DbManager
{
  /**
   * debug信息直接输出到控制台
   */
  const int DEBUG_SAVE_CONSOLE = 1;
  /**
   * debug信息保存到日志文件
   */
  const int DEBUG_SAVE_LOGGER = 2;
  public readonly string $defaultChannel;
  /**
   * @var array<string,Channel> 数据库通道
   */
  protected array $channels = [];
  /**
   * @var Table 高性能共享表
   */
  private Table $table;

  /**
   * @param Config $config 配置管理器
   * @param LogManager $logManager 日志管理器
   * @throws DbException 配置错误
   */
  public function __construct(Config $config, protected LogManager $logManager)
  {
    $channels = $config->get('database.channels', []);
    if (!empty($channels)) {
      $this->defaultChannel = $config->get('database.default', array_key_first($channels));
      foreach ($channels as $key => $driver) $this->addChannel($key, $driver);
    }
    $this->table = new Table(1);
    $this->table->column('debug', Table::TYPE_INT, 4);
    $this->table->column('save', Table::TYPE_INT, 4);
    $this->table->create();
    $debug = $config->get('database.debug', true);
    $save = $config->get('info_save_manner', self::DEBUG_SAVE_CONSOLE | self::DEBUG_SAVE_LOGGER);
    if (!is_int($save)) {
      $save = self::DEBUG_SAVE_CONSOLE | self::DEBUG_SAVE_LOGGER;
    }
    $this->table->set('config', [
      'debug' => $debug ? 1 : 0,
      'save' => $save,
    ]);
  }

  /**
   * 添加一个数据库通道
   *
   * 注意：该方法需在swoole服务器启动之前调用，在工作进程添加的通道不会同步到其他进程。
   *
   * @param string $name 通道名称
   * @param Channel|string|array{channel:string,options:array} $channel 驱动类
   * @return void
   * @throws DbException 配置错误
   */
  public function addChannel(string $name, Channel|string|array $channel): void
  {
    if (is_string($channel)) {
      if (!class_exists($channel)) {
        throw new DbException("{$name}数据库通道配置错误，{$channel}不是一个有效的类", -1);
      }
      $channel = invoke($channel);
    } elseif (is_array($channel)) {
      if (!is_string($channel['channel']) || !class_exists($channel['channel'])) {
        throw new DbException("{$name}数据库通道配置错误，通道类不存在", -1);
      }
      $options = $channel['options'] ?? [];
      if (!is_array($options)) {
        throw new DbException($name . '数据库通道配置错误，options需为数组', -1);
      }
      $channel = invoke($channel['channel'], $options);
    }
    if (!$channel instanceof Channel) {
      throw new DbException($name . '数据库通道配置错误，通道类需继承' . Channel::class, -1);
    }
    $this->channels[strtolower($name)] = $channel;
  }

  /**
   * 设置debug模式
   *
   * @access public
   * @param bool $debug
   * @return void
   */
  public function setDebug(bool $debug): void
  {
    $this->table->set('config', [
      'debug' => $debug ? 1 : 0,
    ]);
  }

  /**
   * 设置调试信息保存方式
   *
   * @access public
   * @param int $manner
   * @return void
   */
  public function setDebugInfoSaveManner(int $manner): void
  {
    $this->table->set('config', [
      'save' => $manner,
    ]);
  }

  /**
   * 保存调试信息
   *
   * @access public
   * @param RunInfo $debugInfo
   * @return void
   */
  public function saveDebugInfo(RunInfo $debugInfo): void
  {
    if ($this->debug()) {
      $manner = $this->debugInfoSaveManner();
      $time = $debugInfo->time['cost_time_s'];
      $sql = $debugInfo->sql->toString();
      $cache = $debugInfo->cache ? 'true' : 'false';
      $log = "$sql [Runtime:{$time}s,Cache:$cache]";
      if ($manner & self::DEBUG_SAVE_CONSOLE) {
        Output::echo($log, 'SQL', backtrace: 0);
      }
      if ($manner & self::DEBUG_SAVE_LOGGER) {
        $this->logManager->sql(
          $log,
          ['sql' => $sql, 'cache' => $debugInfo->cache, 'time' => $debugInfo->time]
        );
      }
    }
  }

  /**
   * 是否开启debug
   *
   * @return bool
   */
  public function debug(): bool
  {
    return (bool)$this->table->get('config')['debug'];
  }

  /**
   * 调试信息保存方式
   *
   * @return int 1 代表控制台，2 代表日志文件，3 代表同时保存到控制台和日志文件
   */
  public function debugInfoSaveManner(): int
  {
    return $this->table->get('config')['save'];
  }

  /**
   * 开启事务(startTransaction别名方法)
   *
   * @return void
   */
  public function start(): void
  {
    $this->startTransaction();
  }

  /**
   * 开启事务
   *
   * @param Closure|null $query 如果传入闭包则自动捕获异常并执行commit|rollBack
   * @return void
   */
  public function startTransaction(Closure $query = null): void
  {
    ConnectManager::factory()->start();
    if ($query instanceof Closure) {
      try {
        $query();
        $this->commit();
      } catch (Throwable) {
        $this->rollBack();
      }
    }
  }

  /**
   * 提交事务
   * @access public
   * @return void
   */
  public function commit(): void
  {
    ConnectManager::factory()->commit();
  }

  /**
   * 回滚所有事务
   *
   * @access public
   * @return void
   */
  public function rollBack(): void
  {
    ConnectManager::factory()->rollBack();
  }

  /**
   * 原生sql
   *
   * @param string $sql 原生sql语句，支持占位符
   * @param array $bindings 绑定参数
   * @return Raw
   */
  public function raw(string $sql, array $bindings = []): Raw
  {
    return new Raw($sql, $bindings);
  }

  /**
   * 转发调用
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   * @throws DbException
   */
  public function __call(string $name, array $arguments)
  {
    $channel = $this->channel();
    if (!method_exists($channel, $name)) {
      throw new InvalidArgumentException('数据库通道 ' . $name . ' 方法不存在');
    }
    return $channel->$name(...$arguments);
  }

  /**
   * 获取数据库通道
   *
   * @access public
   * @param string|null $name 通道名称
   * @return Channel
   * @throws DbException 没有数据库通道
   */
  public function channel(string $name = null): Channel
  {
    if (empty($this->channels)) {
      throw new DbException('数据库通道列表为空，请先配置数据库通道。', -1);
    }
    $name = $name ?? $this->defaultChannel;
    if (!$this->hasChannel($name)) {
      throw new InvalidArgumentException('数据库通道 ' . $name . ' 不存在');
    }
    return $this->channels[strtolower($name)];
  }

  /**
   * 判断通道是否存在
   *
   * @access public
   * @param string $channel_name
   * @return bool
   */
  public function hasChannel(string $channel_name): bool
  {
    return isset($this->channels[strtolower($channel_name)]);
  }

  /**
   * 返回所有通道
   *
   * @access public
   * @return array<string,Channel>
   */
  public function getChannels(): array
  {
    return $this->channels;
  }
}
