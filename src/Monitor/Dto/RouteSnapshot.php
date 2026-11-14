<?php

declare(strict_types=1);

/* 职责：路由模板、路径参数和处理器标识；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

/**
 * 路由模板、路径参数和处理器标识 DTO
 *
 * 属性提供 IDE 类型提示；字段被整组脱敏时保留字符串标记，未采集字段为 null。
 */
final readonly class RouteSnapshot extends SnapshotRecord
{
  public string|null $id;
  /** @var list<string> 声明路由模板 */
  public array $paths;
  public CaptureState $pathsState;
  public CollectionSnapshot $parameters;
  public string|null $handler;

  /**
   * 构建定稿记录
   *
   * @param array<string,mixed> $data 已脱敏的记录字段
   */
  public function __construct(array $data)
  {
    parent::__construct($data);
    $safe = $this->toArray();
    $this->id = ($safe['id'] ?? null);
    $this->pathsState = CaptureState::resolve($safe['paths'] ?? null);
    $this->paths = is_array($safe['paths'] ?? null)
      ? array_values(array_filter($safe['paths'], static fn (mixed $path): bool => is_string($path))) : [];
    $this->parameters = new CollectionSnapshot($safe['parameters'] ?? null);
    $this->handler = ($safe['handler'] ?? null);
  }
}
