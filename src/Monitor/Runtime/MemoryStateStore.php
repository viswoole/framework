<?php

declare(strict_types=1);

/* 职责：提供非 Swoole 环境的本进程状态，保持异常时不发布语义。 */

namespace Viswoole\Monitor\Runtime;

/** 状态不可变，不承诺跨进程同步。 */
final class MemoryStateStore implements StateStore
{
  /**
   * 初始化存储
   * @param RuntimeState $state 初始配置
   */
  public function __construct(private RuntimeState $state) {}

  /** @return RuntimeState 完整不可变状态 */
  public function read(): RuntimeState { return $this->state; }

  /**
   * 转换成功后替换状态
   * @param callable(RuntimeState):RuntimeState $change 内部转换
   * @return RuntimeState 新状态
   */
  public function update(callable $change): RuntimeState
  {
    $next = $change($this->state);
    $this->state = $next;
    return $next;
  }
}
