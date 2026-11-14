<?php

declare(strict_types=1);

/* 职责：承载；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

use Viswoole\Monitor\OperationType;

/**
 * CacheOperationSnapshot 定稿数据
 *
 * 已脱敏展示值保留联合类型；通过 kind 提供不受脱敏影响的分类。
 */
final readonly class CacheOperationSnapshot extends OperationSnapshot
{
  public string|null $store;
  public string|null $operation;
  public array|string|null $key;
  public array|string|null $tags;

  /**
   * 创建操作快照
   *
   * @param array<string,mixed> $data 已脱敏且有界的操作记录
   */
  public function __construct(array $data)
  {
    parent::__construct($data, OperationType::CACHE);
    $safe = $this->toArray();
    $this->store = $safe['store'] ?? null;
    $this->operation = $safe['operation'] ?? null;
    $this->key = $safe['key'] ?? null;
    $this->tags = $safe['tags'] ?? null;
  }
}
