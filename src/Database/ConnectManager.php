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

use PDO;
use Swoole\Database\MysqliProxy;
use Swoole\Database\PDOProxy;
use Viswoole\Core\Coroutine\Context;

/**
 * 连接管理
 */
class ConnectManager
{
  /**
   * @var array<int,array{channel:Channel,connect:mixed,active:bool}> 事务状态中的连接池
   */
  protected array $connections = [];
  /**
   * @var bool 是否状态
   */
  protected bool $inTransaction = false;

  /**
   * 获取当前协程中的连接管理器单例
   *
   * @return static
   */
  public static function factory(): static
  {
    if (Context::has('$_db_transaction')) {
      return Context::get('$_db_transaction');
    } else {
      $instance = new static();
      Context::set('$_db_transaction', $instance);
      return $instance;
    }
  }

  /**
   * 获取连接
   *
   * @param Channel $channel
   * @param string $type
   * @return mixed
   * @noinspection PhpComposerExtensionStubsInspection
   */
  public function pop(Channel $channel, string $type): mixed
  {

    if ($this->inTransaction) {
      // 处于事务中则拿到空闲连接，复用，避免占用过多连接
      foreach ($this->connections as &$item) {
        if ($item['active'] === false && $item['channel'] === $channel) {
          $item['active'] = true;
          return $item['connect'];
        }
      }
      $connect = $channel->pop($type);
      if ($connect instanceof PDOProxy || $connect instanceof PDO) {
        $connect->beginTransaction();
      } elseif ($connect instanceof MysqliProxy || $connect instanceof \mysqli) {
        $connect->autocommit(false);
      }
      $this->connections[] = [
        'connect' => $connect,
        'channel' => $channel,
        'active' => true
      ];
    } else {
      $connect = $channel->pop($type);
    }
    return $connect;
  }

  /**
   * 开启事务
   *
   * @return void
   */
  public function start(): void
  {
    $this->inTransaction = true;
  }

  /**
   * 提交事务
   * @access public
   * @return void
   */
  public function commit(): void
  {
    $array = $this->connections;
    foreach ($array as $key => $item) {
      $item['connect']->commit();
      unset($this->connections[$key]);
      $this->put($item['channel'], $item['connect']);
    }
    $this->close();
  }

  /**
   * 归还连接
   *
   * @param Channel $channel
   * @param mixed $connect
   * @return void
   */
  public function put(Channel $channel, mixed $connect): void
  {
    if ($this->inTransaction) {
      foreach ($this->connections as &$item) {
        if ($item['connect'] === $connect && $item['channel'] === $channel) {
          $item['active'] = false;
          return;
        }
      }
    }
    $channel->put($connect);
  }

  /**
   * 关闭事务
   *
   * @return void
   */
  protected function close(): void
  {
    $this->inTransaction = false;
    $this->connections = [];
  }

  /**
   * 析构函数
   *
   * 回滚所有未完成的事务
   */
  public function __destruct()
  {
    $this->rollback();
  }

  /**
   * 回滚所有事务
   *
   * @access public
   * @return void
   */
  public function rollback(): void
  {
    $array = $this->connections;
    foreach ($array as $key => $item) {
      $item['connect']->rollBack();
      unset($this->connections[$key]);
      $this->put($item['channel'], $item['connect']);
    }
    $this->close();
  }
}
