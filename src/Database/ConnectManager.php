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
use RuntimeException;
use Swoole\Database\MysqliProxy;
use Swoole\Database\PDOProxy;
use Throwable;
use Viswoole\Core\Coroutine\Context;

/**
 * 连接管理器
 *
 * 基于协程上下文的单例，管理当前协程内的数据库连接和事务状态。
 * 事务期间复用同一连接，避免占用过多连接池资源。
 * 析构时自动回滚未完成的事务，防止连接泄漏。
 */
class ConnectManager
{
  /**
   * @var array<int,array{channel:Channel,connect:mixed,active:bool}> 事务状态中的连接池
   */
  protected array $connections = [];
  /** @var bool 是否处于事务中 */
  protected bool $inTransaction = false;

  /**
   * 获取当前协程中的连接管理器单例，首次调用时自动创建
   *
   * @return static 连接管理器实例
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
   * 从通道获取一个连接，事务中复用空闲连接
   *
   * @param Channel $channel 数据库通道
   * @param string $type 连接类型 read|write
   * @return mixed 数据库连接实例
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
   * 标记事务开始
   *
   * @throws RuntimeException 已处于事务中时抛出
   */
  public function start(): void
  {
    if ($this->inTransaction) throw new RuntimeException('同一个进程中不允许开启多个事务');
    $this->inTransaction = true;
  }

  /**
   * 提交事务，释放所有事务连接
   */
  public function commit(): void
  {
    // 修复: 使用 try-finally 确保中途异常时也能重置事务状态并释放连接，避免连接泄漏
    try {
      $array = $this->connections;
      foreach ($array as $key => $item) {
        $item['connect']->commit();
        unset($this->connections[$key]);
        $this->put($item['channel'], $item['connect']);
      }
    } finally {
      $this->close();
    }
  }

  /**
   * 归还连接到通道，事务中仅标记为空闲而非真正归还
   *
   * @param Channel $channel 数据库通道
   * @param mixed $connect 数据库连接实例
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
   * 关闭事务，归还所有连接并重置事务状态
   */
  protected function close(): void
  {
    // 修复: 归还所有尚未释放的连接到连接池，避免 commit/rollBack 中途异常导致连接泄漏
    $array = $this->connections;
    foreach ($array as $key => $item) {
      unset($this->connections[$key]);
      $this->put($item['channel'], $item['connect']);
    }
    $this->inTransaction = false;
    $this->connections = [];
  }

  /**
   * 析构时回滚未完成的事务，防止连接泄漏
   *
   * 析构阶段（协程结束、GC 或请求收尾）连接可能已失效，回滚抛出的
   * 异常无法被调用方捕获，向外抛会引发 PHP 致命错误
   * （"Exception thrown without a stack frame"），因此静默吞掉，
   * 连接的回收交由 rollBack 内部 finally 与连接池的健康检查兜底。
   */
  public function __destruct()
  {
    try {
      $this->rollBack();
    } catch (Throwable) {
      // 析构阶段无法向外传递异常，吞掉以避免致命错误
    }
  }

  /**
   * 回滚事务，释放所有事务连接
   */
  public function rollBack(): void
  {
    // 修复: 使用 try-finally 确保中途异常时也能重置事务状态并释放连接，避免连接泄漏
    try {
      $array = $this->connections;
      foreach ($array as $key => $item) {
        $item['connect']->rollBack();
        unset($this->connections[$key]);
        $this->put($item['channel'], $item['connect']);
      }
    } finally {
      $this->close();
    }
  }
}
