<?php

declare(strict_types=1);

/* 职责：定义一致性读与内部事务更新边界。 */

namespace Viswoole\Monitor\Runtime;

/** 回调仅供框架同步管理逻辑使用，不作为项目决策扩展点。 */
interface StateStore
{
  /** @return RuntimeState 不可变的一致状态 */
  public function read(): RuntimeState;

  /**
   * 原子发布新状态
   * @param callable(RuntimeState):RuntimeState $change 框架内部纯转换，冲突时允许重跑，不得执行外部副作用
   * @return RuntimeState 发布后的状态
   */
  public function update(callable $change): RuntimeState;
}
