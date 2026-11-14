<?php

declare(strict_types=1);

/* 职责：承载；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

use Viswoole\Monitor\OperationType;

/**
 * DatabaseOperationSnapshot 定稿数据
 *
 * 已脱敏展示值保留联合类型；通过 kind 提供不受脱敏影响的分类。
 */
final readonly class DatabaseOperationSnapshot extends OperationSnapshot
{
  public string|null $channel;
  public string|null $route;
  public string|null $requestedRoute;
  public string|null $sql;
  public array|string|null $bindings;
  public int|string|null $bindingCount;
  public bool|string|null $bindingsTruncated;

  /**
   * 创建操作快照
   *
   * @param array<string,mixed> $data 已脱敏且有界的操作记录
   */
  public function __construct(array $data)
  {
    parent::__construct($data, OperationType::DATABASE);
    $safe = $this->toArray();
    $this->channel = $safe['channel'] ?? null;
    $this->route = $safe['route'] ?? null;
    $this->requestedRoute = $safe['requested_route'] ?? null;
    $this->sql = $safe['sql'] ?? null;
    $this->bindings = $safe['bindings'] ?? null;
    $this->bindingCount = $safe['binding_count'] ?? null;
    $this->bindingsTruncated = $safe['bindings_truncated'] ?? null;
  }
}
