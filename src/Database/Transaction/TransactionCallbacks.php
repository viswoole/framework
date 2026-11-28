<?php

declare(strict_types=1);

/* 职责：按事务保存点管理提交后回调，隔离回调故障，不持有连接或执行提交。 */

namespace Viswoole\Database\Transaction;

use Closure;
use Throwable;
use Viswoole\Database\DbLog;

/**
 * 当前事务上下文的提交后回调集合
 */
final class TransactionCallbacks
{
  /**
   * @var array<int,array<int,Closure>> 各层注册的回调，内层确认提交后才合并到父层
   */
  private array $levels = [];

  /**
   * 登记当前层回调
   *
   * 层级由连接管理器决定，本对象不推断事务状态。
   *
   * @param int $level 当前事务层级
   * @param Closure $callback 无参数的提交后操作
   * @return void 回调已登记
   */
  public function add(int $level, Closure $callback): void
  {
    $this->levels[$level][] = $callback;
  }

  /**
   * 将内层回调转交父层
   *
   * 仅在保存点释放成功后调用，保持当前上下文内注册顺序。
   *
   * @param int $level 已确认提交的内层层级
   * @return void 回调已合并且旧层已移除
   */
  public function release(int $level): void
  {
    foreach ($this->levels[$level] ?? [] as $callback) {
      $this->levels[$level - 1][] = $callback;
    }
    $this->discard($level);
  }

  /**
   * 丢弃回滚或失效层的回调
   *
   * 同层再次开启事务时不能复用上一轮回调。
   *
   * @param int $level 结束的层级
   * @return void 当前层回调已移除
   */
  public function discard(int $level): void
  {
    unset($this->levels[$level]);
  }

  /**
   * 分离最外层回调
   *
   * 提交前分离后由调用方保管；提交失败时直接丢弃，成功清理上下文后才执行。
   *
   * @return array<int,Closure> 当前外层的有序回调
   */
  public function detach(): array
  {
    $callbacks = $this->levels[1] ?? [];
    $this->levels = [];
    return $callbacks;
  }

  /**
   * 独立执行已确认提交后的操作
   *
   * 已提交事务不能被通知或日志故障撤销；逐条隔离并避免记录闭包捕获的敏感数据。
   *
   * @param array<int,Closure> $callbacks 待执行回调
   * @return void 所有回调均已尝试，失败已单独留痕
   */
  public static function invoke(array $callbacks): void
  {
    foreach ($callbacks as $callback) {
      try {
        $callback();
      } catch (Throwable $error) {
        DbLog::write('error', sprintf('事务提交后回调失败：%s (%s)；已提交结果保持不变', $error::class, $error->getCode()));
      }
    }
  }
}
