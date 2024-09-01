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
use Throwable;
use Viswoole\Core\Common\Str;
use Viswoole\Core\Config;
use Viswoole\Database\Exception\DbException;

/**
 * 数据库通道管理器
 *
 * @method Query table(string $table, string $pk = 'id') 选择要查询的表
 * @method array query(string $sql, array $bindings = []) 原生查询 select
 * @method int|string execute(string $sql, array $bindings = []) 原生写入，包括 insert、update、delete
 * @method mixed pop(string $type) 获取可用的连接$type可选值为`read`|`write`
 * @method void put(mixed $connect) 归还一个可用的连接，如果连接已被损坏，请归还null
 */
class Db
{
  public readonly string $defaultChannel;
  /**
   * @var array<string,Channel> 数据库通道
   */
  protected array $channels = [];

  /**
   * @param Config $config
   */
  public function __construct(protected Config $config)
  {
    $channels = $config->get('database.channel', []);
    if (!empty($channels)) {
      $this->defaultChannel = $config->get('database.default', array_key_first($channels));
      foreach ($channels as $key => $driver) {
        if (!$driver instanceof Channel) {
          throw new DbException(
            '数据库通道 ' . $key . ' 驱动类需继承' . Channel::class . '抽象类'
          );
        }
        $key = Str::camelCaseToSnakeCase($key);
        $this->channels[$key] = $driver;
      }
    }
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
   * @param Closure|null $query 如果传入闭包则自动捕获异常并执行commit|rollback
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
        $this->rollback();
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
  public function rollback(): void
  {
    ConnectManager::factory()->rollback();
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
   */
  public function channel(string $name = null): Channel
  {
    $name = $name ?? $this->defaultChannel;
    if (!$this->hasChannel($name)) {
      throw new InvalidArgumentException('数据库通道 ' . $name . ' 不存在');
    }
    return $this->channels[Str::camelCaseToSnakeCase($name)];
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
    return isset($this->channels[Str::camelCaseToSnakeCase($channel_name)]);
  }
}
