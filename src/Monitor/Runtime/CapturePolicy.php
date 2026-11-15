<?php

declare(strict_types=1);

/* 职责：固定单请求使用的配置版本、接口身份和规则，不读取最新运行态。 */

namespace Viswoole\Monitor\Runtime;

use Viswoole\Router\RouteIdentity;

/** 由一致性状态构造；正常慢请求与每条规则分别判定。 */
final readonly class CapturePolicy
{
  public float $globalThresholdMs;
  public float $effectiveThresholdMs;
  /** @var list<OverrideRule> */
  public array $rules;

  /**
   * 固定请求策略
   * @param RuntimeState $state 同一版本配置
   * @param RouteIdentity|null $route 可信匹配身份
   */
  public function __construct(public RuntimeState $state, public ?RouteIdentity $route = null)
  {
    $this->globalThresholdMs = $state->thresholdMs;
    $this->rules = $route === null ? [] : ($state->byRoute[$route->key()] ?? []);
    $threshold = $state->thresholdMs;
    foreach ($this->rules as $rule) { $threshold = min($threshold, $rule->thresholdMs); }
    $this->effectiveThresholdMs = $threshold;
  }

  /**
   * 输出真正达到门槛的命中来源
   * @param float $durationMs 最终耗时
   * @return array<string,mixed> 完整采集依据
   */
  public function describe(float $durationMs): array
  {
    $matched = [];
    foreach ($this->rules as $rule) {
      if ($durationMs >= $rule->thresholdMs) { $matched[] = $rule->identity(); }
    }
    return ['runtime_id' => $this->state->runtimeId, 'config_version' => $this->state->version,
      'route_identity' => $this->route?->toArray(), 'global_threshold_ms' => $this->globalThresholdMs,
      'effective_threshold_ms' => $this->effectiveThresholdMs,
      'matched_global' => $durationMs >= $this->globalThresholdMs, 'matched_overrides' => $matched];
  }
}
