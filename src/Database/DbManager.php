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

namespace Viswoole\Database;

use Closure;
use InvalidArgumentException;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
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
    } else {
      // 空配置时也必须初始化 readonly 属性，
      // 否则运行时 addChannel() 动态注册后再访问 channel() 会因
      // 属性未初始化抛出 Error（channel() 内会回退到首个已注册通道）
      $this->defaultChannel = '';
    }
    $this->table = new Table(1);
    $this->table->column('debug', Table::TYPE_INT, 4);
    $this->table->column('save', Table::TYPE_INT, 4);
    $this->table->create();
    $debug = $config->get('database.debug', true);
    // 修复: 配置路径错误，应与 database.debug 一致使用 database 命名空间下的 info_save_manner
    $save = $config->get(
      'database.info_save_manner', self::DEBUG_SAVE_CONSOLE | self::DEBUG_SAVE_LOGGER
    );
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
   * 开启事务
   *
   * @throws Throwable 闭包抛出的异常在回滚后原样重抛
   */
  public function start(): void
  {
    $this->startTransaction();
  }

  /**
   * 开启事务（支持嵌套）
   *
   * 传入闭包时自动管理事务：闭包执行成功则提交并返回闭包返回值，异常则回滚并重抛。
   * 未传闭包时仅开启事务（等同 start()），返回 null。
   *
   * 嵌套事务：同一协程内可多次开启，内层基于数据库 SAVEPOINT 实现。
   * commit/rollBack 始终作用于当前最内层事务，需按后开先关（LIFO）顺序收尾：
   * - 内层 rollBack 仅回滚到该层保存点，外层已完成的操作不受影响，外层事务可继续；
   * - 内层 commit 仅释放保存点，数据仍受最外层事务保护，最外层 commit 才真正落库；
   * - 层级中途加入事务的连接会自动补齐各层保存点，跨通道嵌套同样适用。
   *
   * 限制：跨通道/跨库事务不保证原子性。commit 按连接加入顺序逐个提交（无两阶段提交），
   * 中途某通道提交失败时，先前提交通道的数据已落库（部分提交），失败通道会被兜底回滚。
   * 需要跨库原子性的业务应避免在同一事务内混合写入不同通道，或自行实现补偿逻辑。
   *
   * @param Closure|null $query 闭包内执行事务操作，传入后自动 commit/rollBack
   * @return mixed 闭包的返回值；未传闭包时返回 null
   * @throws Throwable 闭包抛出的异常在回滚后原样重抛
   */
  public function startTransaction(?Closure $query = null): mixed
  {
    ConnectManager::factory()->start();
    if ($query instanceof Closure) {
      try {
        // 先捕获闭包返回值，提交成功后透传给调用方（修复：原实现丢弃返回值）
        $result = $query();
        $this->commit();
        return $result;
      } catch (Throwable $e) {
        $this->rollBack();
        throw $e;
      }
    }
    return null;
  }

  /**
   * 提交当前最内层事务
   *
   * 嵌套层提交仅释放该层保存点（数据仍受外层保护）；
   * 最外层提交真正落库并释放所有事务连接。
   */
  public function commit(): void
  {
    ConnectManager::factory()->commit();
  }

  /**
   * 回滚当前最内层事务
   *
   * 嵌套层回滚仅回滚到该层保存点（外层操作不受影响，外层事务可继续）；
   * 最外层回滚丢弃全部数据并释放所有事务连接。
   */
  public function rollBack(): void
  {
    ConnectManager::factory()->rollBack();
  }

  /**
   * 获取当前事务嵌套层级
   *
   * @return int 0 表示当前协程未开启事务，N 表示存在 N 层嵌套事务
   */
  public function transactionLevel(): int
  {
    return ConnectManager::factory()->transactionLevel();
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
   * 执行原生查询（SELECT），返回关联数组结果集
   *
   * 与 think-orm 保持一致：读写分离场景下默认从读库执行，
   * 传入 $master=true 时强制从主库（写库）读取。
   *
   * @param string|Raw $sql SQL语句或 Raw 对象
   * @param array $bindings 绑定参数，与 SQL 中的占位符对应
   * @param bool $master 是否强制从主库读取
   * @return array 查询结果数组，每个元素为一行关联数组
   * @throws DbException SQL 执行失败或通道返回类型不支持时抛出
   */
  public function query(string|Raw $sql, array $bindings = [], bool $master = false): array
  {
    return $this->channel()->query($sql, $bindings, $master);
  }

  /**
   * 获取指定名称的数据库通道
   *
   * @param string|null $name 通道名称，为 null 时使用默认通道
   * @return Channel 数据库通道实例
   * @throws DbException 通道列表为空时抛出
   * @throws InvalidArgumentException 指定通道不存在时抛出
   */
  public function channel(?string $name = null): Channel
  {
    if (empty($this->channels)) {
      throw new DbException('数据库通道列表为空，请先配置数据库通道。', -1);
    }
    if ($name === null) {
      // 默认通道未配置（空配置后动态注册）或已失效时，回退到首个已注册通道
      $name = ($this->defaultChannel !== '' && $this->hasChannel($this->defaultChannel))
        ? $this->defaultChannel
        : (string)array_key_first($this->channels);
    }
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
   * 执行原生写入（INSERT/UPDATE/DELETE），返回受影响行数
   *
   * 与 think-orm 保持一致：始终在写库执行，返回影响的记录数；
   * 传入 $getId 时返回该字段的自增ID（部分驱动以字符串返回）。
   *
   * @param string|Raw $sql SQL语句或 Raw 对象
   * @param array $bindings 绑定参数，与 SQL 中的占位符对应
   * @param false|string $getId 传入字段名时返回该字段的自增ID，false 时不获取
   * @return int|string 受影响行数或自增ID
   * @throws DbException SQL 执行失败时抛出
   */
  public function execute(string|Raw $sql, array $bindings = [], false|string $getId = false
  ): int|string
  {
    $result = $this->channel()->execute($sql, $bindings, $getId);
    if ($result instanceof PDOStatement || $result instanceof PDOStatementProxy) {
      $count = $result->rowCount();
      $result->closeCursor();
      return $count;
    }
    // 非 PDO 通道：受影响行数或自增ID，契约保证为 int|string，无需强转
    return $result;
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
      // 修复: 原文案把方法名误标为通道名（"数据库通道 xxx 方法不存在"）
      $channelClass = $channel::class;
      throw new InvalidArgumentException("数据库通道 $channelClass 不存在 $name 方法");
    }
    return $channel->$name(...$arguments);
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
