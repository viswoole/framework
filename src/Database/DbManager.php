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
 * 负责数据库通道的注册、切换与调试信息管理，通过 Swoole\Table 实现跨进程共享调试配置。
 * 所有数据库操作均通过通道代理执行，支持事务管理和原生SQL表达式构建。
 *
 * @method BaseQuery table(string $table, string $pk = 'id') 选择要查询的表
 * @method array query(string $sql, array $bindings = []) 原生查询 select
 * @method int|string execute(string $sql, array $bindings = []) 原生写入，包括 insert、update、delete
 * @method mixed pop(string $type) 获取可用的连接$type可选值为`read`|`write`
 * @method void put(mixed $connect) 归还一个可用的连接，如果连接已被损坏，请归还null
 * @see Channel
 */
class DbManager
{
  /**
   * 调试信息输出到控制台
   */
  const int DEBUG_SAVE_CONSOLE = 1;
  /**
   * 调试信息保存到日志文件
   */
  const int DEBUG_SAVE_LOGGER = 2;
  public readonly string $defaultChannel;
  /**
   * @var array<string,Channel> 已注册的数据库通道，键名为通道小写名称
   */
  protected array $channels = [];
  /**
   * @var Table 跨进程共享的调试配置表
   */
  private Table $table;

  /**
   * 初始化数据库管理器，注册通道并配置调试模式
   *
   * @param Config $config 配置管理器，用于读取 database 配置项
   * @param LogManager $logManager 日志管理器，用于调试信息写入日志
   * @throws DbException 通道配置错误时抛出
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
    // 修复: 配置路径错误，应与 database.debug 一致使用 database 命名空间下的 info_save_manner
    $save = $config->get('database.info_save_manner', self::DEBUG_SAVE_CONSOLE | self::DEBUG_SAVE_LOGGER);
    if (!is_int($save)) {
      $save = self::DEBUG_SAVE_CONSOLE | self::DEBUG_SAVE_LOGGER;
    }
    $this->table->set('config', [
      'debug' => $debug ? 1 : 0,
      'save' => $save,
    ]);
  }

  /**
   * 注册一个数据库通道
   *
   * 注意：该方法需在 Swoole 服务器启动之前调用，在工作进程添加的通道不会同步到其他进程。
   *
   * @param string $name 通道名称，不区分大小写
   * @param Channel|string|array{driver:string,options:array} $channel 通道实例、类名或配置数组（含 driver 和 options 键）
   * @throws DbException 通道类不存在、未实现 Channel 接口或配置格式错误时抛出
   */
  public function addChannel(string $name, Channel|string|array $channel): void
  {
    if (is_string($channel)) {
      if (!class_exists($channel)) {
        throw new DbException("{$name}数据库通道配置错误，{$channel}不是一个有效的类", -1);
      }
      $channel = invoke($channel);
    } elseif (is_array($channel)) {
      if (!is_string($channel['driver']) || !class_exists($channel['driver'])) {
        throw new DbException("{$name}数据库通道配置错误，通道类不存在", -1);
      }
      $options = $channel['options'] ?? [];
      if (!is_array($options)) {
        throw new DbException($name . '数据库通道配置错误，options需为数组', -1);
      }
      $channel = invoke($channel['driver'], $options);
    }
    if (!$channel instanceof Channel) {
      throw new DbException($name . '数据库通道配置错误，通道类需继承' . Channel::class, -1);
    }
    $this->channels[strtolower($name)] = $channel;
  }

  /**
   * 切换调试模式开关
   *
   * @param bool $debug true 开启调试，false 关闭调试
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
   * @param int $manner 保存方式，可使用 DEBUG_SAVE_CONSOLE、DEBUG_SAVE_LOGGER 或位运算组合
   */
  public function setDebugInfoSaveManner(int $manner): void
  {
    $this->table->set('config', [
      'save' => $manner,
    ]);
  }

  /**
   * 根据当前调试配置保存查询运行信息
   *
   * 仅在调试模式开启时生效，按配置的保存方式输出到控制台和/或日志文件。
   *
   * @param RunInfo $debugInfo 查询运行信息
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
   * 判断调试模式是否开启
   *
   * @return bool 调试模式开启返回 true
   */
  public function debug(): bool
  {
    return (bool)$this->table->get('config')['debug'];
  }

  /**
   * 获取调试信息保存方式
   *
   * @return int 1=控制台，2=日志文件，3=同时保存到控制台和日志文件
   */
  public function debugInfoSaveManner(): int
  {
    return $this->table->get('config')['save'];
  }

  /**
   * 开启事务（startTransaction 的简写）
   *
   * @see startTransaction()
   */
  public function start(): void
  {
    $this->startTransaction();
  }

  /**
   * 开启事务
   *
   * 传入闭包时自动管理事务：闭包执行成功则提交，异常则回滚。
   *
   * @param Closure|null $query 闭包内执行事务操作，传入后自动 commit/rollBack
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
   * 提交当前事务
   */
  public function commit(): void
  {
    ConnectManager::factory()->commit();
  }

  /**
   * 回滚当前事务
   */
  public function rollBack(): void
  {
    ConnectManager::factory()->rollBack();
  }

  /**
   * 创建原生SQL表达式对象，用于在查询构建中嵌入不被参数绑定的SQL片段
   *
   * @param string $sql 原生SQL语句，支持位置占位符(?)和命名占位符(:name)
   * @param array $bindings 绑定参数，与占位符对应
   * @return Raw 原生SQL表达式对象
   */
  public function raw(string $sql, array $bindings = []): Raw
  {
    return new Raw($sql, $bindings);
  }

  /**
   * 将方法调用转发到默认数据库通道
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed 通道方法的返回值
   * @throws DbException 通道方法不存在时抛出
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
   * 获取指定名称的数据库通道
   *
   * @param string|null $name 通道名称，为 null 时使用默认通道
   * @return Channel 数据库通道实例
   * @throws DbException 通道列表为空时抛出
   * @throws InvalidArgumentException 指定通道不存在时抛出
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
   * 判断指定名称的通道是否已注册
   *
   * @param string $channel_name 通道名称，不区分大小写
   * @return bool 通道已注册返回 true
   */
  public function hasChannel(string $channel_name): bool
  {
    return isset($this->channels[strtolower($channel_name)]);
  }

  /**
   * 获取所有已注册的数据库通道
   *
   * @return array<string,Channel> 键为通道名称，值为通道实例
   */
  public function getChannels(): array
  {
    return $this->channels;
  }
}
