<?php

declare(strict_types=1);

/* 职责：承载操作共有时间与结果信息；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

use Viswoole\Monitor\OperationType;

/**
 * 操作定稿基类
 *
 * kind 是内部可信分类，type 仅是可能被脱敏的展示字段，两者不能混用。
 */
abstract readonly class OperationSnapshot extends SnapshotRecord
{
  public ?string $type;
  public float|string|null $offsetMs;
  public float|string|null $durationMs;
  public bool|string|null $success;
  public ?string $exception;
  public ?string $resultType;
  public int|float|bool|string|null $result;
  public int|string|null $resultCount;

  /**
   * 创建共有操作数据
   *
   * @param array<string,mixed> $data 已脱敏操作记录
   * @param OperationType $kind 内部可信分类
   */
  public function __construct(array $data, public OperationType $kind)
  {
    parent::__construct($data);
    $safe = $this->toArray();
    $this->type = $safe['type'] ?? null;
    $this->offsetMs = $safe['offset_ms'] ?? null;
    $this->durationMs = $safe['duration_ms'] ?? null;
    $this->success = $safe['success'] ?? null;
    $this->exception = $safe['exception'] ?? null;
    $this->resultType = $safe['result_type'] ?? null;
    $this->result = $safe['result'] ?? null;
    $this->resultCount = $safe['result_count'] ?? null;
  }
}
