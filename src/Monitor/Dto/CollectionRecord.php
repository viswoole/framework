<?php

declare(strict_types=1);

/* 职责：集中解析集合的安全副本和采集状态；具体集合负责自己的值类型约束。 */

namespace Viswoole\Monitor\Dto;

/**
 * 不可变集合快照基类
 *
 * 统一隐藏、截断状态与占位字段清理，不将数据内容约束耦合到公共解析流程。
 */
abstract readonly class CollectionRecord extends SnapshotRecord
{
  public CaptureState $state;
  public bool $isRedacted;
  public bool $isTruncated;

  /**
   * 复制集合并解析状态
   *
   * @param mixed $value 原集合或日志占位标记
   */
  public function __construct(mixed $value)
  {
    parent::__construct(['value' => $value]);
    $this->state = CaptureState::resolve($this->toArray()['value']);
    $this->isRedacted = $this->state === CaptureState::REDACTED;
    $this->isTruncated = $this->state === CaptureState::TRUNCATED;
  }

  /**
   * 取得排除状态标记后的集合
   *
   * @return array<array-key,mixed> 安全数组，整组省略时为空
   */
  protected function collectionValues(): array
  {
    $safe = $this->toArray()['value'];
    $values = is_array($safe) ? $safe : [];
    unset($values['__truncated']);
    return $values;
  }
}
