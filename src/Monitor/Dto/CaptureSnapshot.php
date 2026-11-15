<?php

declare(strict_types=1);

/* 职责：承载采集来源，writer 可分别处理正常慢请求和临时规则。 */

namespace Viswoole\Monitor\Dto;

use Viswoole\Router\RouteIdentity;

/** 来源信息不参与业务数据脱敏，规则 ID 不得存放个人信息。 */
final readonly class CaptureSnapshot extends SnapshotRecord
{
  public string $runtimeId;
  public int $configVersion;
  public ?RouteIdentity $routeIdentity;
  public float $globalThresholdMs;
  public float $effectiveThresholdMs;
  public bool $matchedGlobal;
  /** @var list<OverrideMatch> */
  public array $matchedOverrides;

  /**
   * 创建不可变来源信息
   * @param array<string,mixed> $data 完整来源记录
   */
  public function __construct(array $data)
  {
    parent::__construct($data);
    $this->runtimeId = $data['runtime_id'];
    $this->configVersion = $data['config_version'];
    $route = $data['route_identity'];
    $this->routeIdentity = $route === null ? null : new RouteIdentity(
      $route['method'],
      $route['template'],
      $route['domain_constraints']
    );
    $this->globalThresholdMs = (float)$data['global_threshold_ms'];
    $this->effectiveThresholdMs = (float)$data['effective_threshold_ms'];
    $this->matchedGlobal = $data['matched_global'];
    $this->matchedOverrides = array_map(
      static fn (array $rule): OverrideMatch => new OverrideMatch($rule),
      $data['matched_overrides']
    );
  }
}
