<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Database;

use PDO;
use Swoole\Database\MysqliProxy;
use Swoole\Database\PDOProxy;
use Throwable;
use Viswoole\Core\Coroutine\Context;

/**
 * 连接管理器
 *
 * 基于协程上下文的单例，管理当前协程内的数据库连接和事务状态。
 * 事务期间复用同一连接，避免占用过多连接池资源。
 * 支持事务嵌套：内层事务基于 SAVEPOINT 实现，可独立回滚而不影响外层。
 * 析构时自动回滚未完成的事务，防止连接泄漏。
 *
 * 嵌套实现要点：
 * 1. 用嵌套计数 transactionDepth 取代单一开关，commit/rollBack 始终作用于最内层（LIFO）；
 * 2. 层级升到 N（N>=2）时在所有事务连接上声明保存点 viswoole_sp_N，
 *    层级中途加入事务的连接在 pop 时补齐 2..N 层保存点，
 *    由此维持不变式"事务池中每个连接都持有每一层的回滚锚点"；
 * 3. 内层 rollBack 即 ROLLBACK TO SAVEPOINT（只丢弃该层工作，外层可继续），
 *    内层 commit 即 RELEASE SAVEPOINT（数据仍受外层保护，最外层提交才真正落库）。
 */
class ConnectManager
{
  /**
   * @var array<int,array{channel:Channel,connect:mixed,active:bool}> 事务状态中的连接池
   */
  protected array $connections = [];
  /** @var int 当前事务嵌套层级，0 表示未开启事务 */
  protected int $transactionDepth = 0;

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
   * 获取当前事务嵌套层级
   *
   * @return int 0 表示当前协程未开启事务，N 表示存在 N 层嵌套事务
   */
  public function transactionLevel(): int
  {
    return $this->transactionDepth;
  }

  /**
   * 从通道获取一个连接，事务中复用空闲连接
   *
   * 嵌套层级中途加入事务的新连接会补齐 2..当前层级 的保存点：
   * 虽然它在第 N 层才加入，但其写入发生在 1..N 所有层的语义范围内，
   * 任意外层回滚都必须能撤销这些写入，故需要所有层的回滚锚点。
   *
   * @param Channel $channel 数据库通道
   * @param string $type 连接类型 read|write
   * @return mixed 数据库连接实例
   */
  public function pop(Channel $channel, string $type): mixed
  {

    if ($this->transactionDepth > 0) {
      // 处于事务中则拿到空闲连接，复用，避免占用过多连接
      // 复用连接已持有全部层级保存点（start 声明 + pop 补齐），无需额外处理
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
      for ($level = 2; $level <= $this->transactionDepth; $level++) {
        $this->executeSavepointSql($connect, 'SAVEPOINT ' . self::savepointName($level));
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
   * 在连接上执行保存点语句（SAVEPOINT / RELEASE SAVEPOINT / ROLLBACK TO SAVEPOINT）
   *
   * 驱动分派与 pop() 的事务开启逻辑保持一致：PDO 系走 exec()，
   * mysqli 系走 query()；其余驱动类型静默跳过（与不开启事务的行为对齐）。
   *
   * @param mixed $connect 底层数据库连接
   * @param string $sql 保存点语句
   */
  private function executeSavepointSql(mixed $connect, string $sql): void
  {
    if ($connect instanceof PDO || $connect instanceof PDOProxy) {
      $connect->exec($sql);
    } elseif ($connect instanceof MysqliProxy || $connect instanceof \mysqli) {
      $connect->query($sql);
    }
  }

  /**
   * 生成指定嵌套层级的保存点名称
   *
   * 带 viswoole_ 前缀，避免与用户自行声明的保存点命名冲突。
   *
   * @param int $level 嵌套层级（>=2，首层为真实事务无保存点）
   * @return string 保存点名称
   */
  private static function savepointName(int $level): string
  {
    return 'viswoole_sp_' . $level;
  }

  /**
   * 开启事务（支持嵌套）
   *
   * 首层开启真实事务（连接被 pop 时才真正 BEGIN）；
   * 嵌套层在所有已加入事务的连接上声明保存点，使内层可独立回滚。
   * 调用方需按后开先关（LIFO）顺序收尾各层。
   */
  public function start(): void
  {
    $level = $this->transactionDepth + 1;
    if ($level > 1) {
      // 先声明保存点再提升层级计数：保存点创建失败（如连接失效）时
      // 层级计数不至错乱；残余保存点无害（该层未成功开启，不会被引用）
      $sql = 'SAVEPOINT ' . self::savepointName($level);
      foreach ($this->connections as $item) {
        $this->executeSavepointSql($item['connect'], $sql);
      }
    }
    $this->transactionDepth = $level;
  }

  /**
   * 提交当前最内层事务
   *
   * 嵌套层提交仅释放该层保存点，数据仍处于最外层事务保护中；
   * 首层提交才真正落库，并释放所有事务连接。
   * 内层释放保存点失败时异常向外抛（层级已在 finally 回退），
   * 失效连接留在事务池中，由外层收尾或析构时 forcePut 兜底回收。
   */
  public function commit(): void
  {
    if ($this->transactionDepth === 0) return;
    if ($this->transactionDepth > 1) {
      $sql = 'RELEASE SAVEPOINT ' . self::savepointName($this->transactionDepth);
      try {
        foreach ($this->connections as $item) {
          $this->executeSavepointSql($item['connect'], $sql);
        }
      } finally {
        // 中途异常也必须回退层级，避免后续 commit/rollBack 作用于错误层级
        $this->transactionDepth--;
      }
      return;
    }
    // 首层：真实提交。使用 try-finally 确保中途异常时也能重置事务状态并释放连接，避免连接泄漏
    try {
      $array = $this->connections;
      foreach ($array as $key => $item) {
        $item['connect']->commit();
        unset($this->connections[$key]);
        // 事务释放路径必须强制归还：此时事务标志尚未重置，
        // 走 put() 会落入"仅标记非活跃"分支导致连接滞留
        $this->forcePut($item['channel'], $item['connect']);
      }
    } finally {
      $this->close();
    }
  }

  /**
   * 强制归还连接到通道连接池（绕过事务标记分支），并兜底回滚未完成事务
   *
   * commit/rollBack/close 释放事务连接时使用：此时尚未重置事务标志，
   * 不能走 put()（其只会把连接标记为非活跃），必须真正归还连接池。
   * 连接若仍处于活跃事务（如 commit 因网络失败抛异常），归还前先回滚，
   * 避免带事务状态的连接回池后被其他协程复用造成隐式事务污染。
   *
   * @param Channel $channel 连接所属通道
   * @param mixed $connect 底层连接
   */
  private function forcePut(Channel $channel, mixed $connect): void
  {
    if ($connect instanceof PDO || $connect instanceof PDOProxy) {
      if ($connect->inTransaction()) {
        try {
          $connect->rollBack();
        } catch (Throwable) {
          // 回滚失败说明连接已失效，仍归还交由连接池健康检查淘汰
        }
      }
    }
    $channel->put($connect);
  }

  /**
   * 回滚当前最内层事务
   *
   * 嵌套层回滚仅回滚到该层保存点：只丢弃该层作用域内的写入，
   * 外层已完成的操作不受影响，外层事务可继续使用；
   * 首层回滚丢弃全部数据并释放所有事务连接。
   * 内层回滚失败时异常向外抛（层级已在 finally 回退），
   * 失效连接留在事务池中，由外层收尾或析构时 forcePut 兜底回收。
   */
  public function rollBack(): void
  {
    if ($this->transactionDepth === 0) return;
    if ($this->transactionDepth > 1) {
      $sql = 'ROLLBACK TO SAVEPOINT ' . self::savepointName($this->transactionDepth);
      try {
        foreach ($this->connections as $item) {
          $this->executeSavepointSql($item['connect'], $sql);
        }
      } finally {
        // 中途异常也必须回退层级，避免后续 commit/rollBack 作用于错误层级
        $this->transactionDepth--;
      }
      return;
    }
    $this->rollBackAll();
  }

  /**
   * 全量回滚最外层事务并释放所有连接
   *
   * 首层 rollBack 与析构兜底共用：无视当前嵌套层级，
   * 对每个事务连接执行真实回滚并强制归还连接池。
   * 嵌套未收尾时无需逐层回滚——最终结果与逐层回滚等价，且少执行 N-1 条语句。
   */
  private function rollBackAll(): void
  {
    // 使用 try-finally 确保中途异常时也能重置事务状态并释放连接，避免连接泄漏
    try {
      $array = $this->connections;
      foreach ($array as $key => $item) {
        $item['connect']->rollBack();
        unset($this->connections[$key]);
        // 强制归还：见 commit() 中说明，避免落入事务标记分支
        $this->forcePut($item['channel'], $item['connect']);
      }
    } finally {
      $this->close();
    }
  }

  /**
   * 关闭事务，归还所有连接并重置事务状态
   */
  protected function close(): void
  {
    // 归还所有尚未释放的连接到连接池，避免 commit/rollBack 中途异常导致连接泄漏
    $array = $this->connections;
    foreach ($array as $key => $item) {
      unset($this->connections[$key]);
      // 强制归还：见 commit() 中说明，此时不能走 put() 的事务标记分支
      $this->forcePut($item['channel'], $item['connect']);
    }
    $this->transactionDepth = 0;
    $this->connections = [];
  }

  /**
   * 归还连接到通道，事务中仅标记为空闲而非真正归还
   *
   * @param Channel $channel 数据库通道
   * @param mixed $connect 数据库连接实例
   */
  public function put(Channel $channel, mixed $connect): void
  {
    if ($this->transactionDepth > 0) {
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
   * 析构时回滚未完成的事务，防止连接泄漏
   *
   * 无论遗留多少层嵌套事务，均直接全量回滚最外层事务。
   * 析构阶段（协程结束、GC 或请求收尾）连接可能已失效，回滚抛出的
   * 异常无法被调用方捕获，向外抛会引发 PHP 致命错误
   * （"Exception thrown without a stack frame"），因此静默吞掉，
   * 连接的回收交由 rollBackAll 内部 finally 与连接池的健康检查兜底。
   */
  public function __destruct()
  {
    try {
      $this->rollBackAll();
    } catch (Throwable) {
      // 析构阶段无法向外传递异常，吞掉以避免致命错误
    }
  }
}
