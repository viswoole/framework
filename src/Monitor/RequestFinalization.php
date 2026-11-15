<?php

declare(strict_types=1);

/* 职责：固定请求策略并在达到保存门槛后构建定稿；不负责过程采集。 */

namespace Viswoole\Monitor;

use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use Viswoole\Monitor\Runtime\CaptureBudget;
use Viswoole\Monitor\Runtime\CapturePolicy;
use Viswoole\Monitor\Runtime\ObservationFailure;
use Viswoole\Router\RouteIdentity;

/** 仅由 RequestTrace 使用，策略与共享配置生命周期分离。 */
trait RequestFinalization
{
  private CapturePolicy $capturePolicy;
  private ?MonitorRuntime $runtime = null;
  private bool $isPolicyFrozen = false;

  /**
   * 绑定共享运行态并读取入口全局阈值
   * @param MonitorRuntime $runtime 框架共享运行态
   * @return void
   */
  public function attachRuntime(MonitorRuntime $runtime): void
  {
    if ($this->isClosed || $this->isPolicyFrozen) { return; }
    $this->runtime = $runtime;
    try { $this->capturePolicy = $runtime->resolve(); }
    catch (\Throwable $error) { ObservationFailure::report($error); }
  }

  /**
   * 成功路由匹配后固定阈值与候选规则
   * @param RouteIdentity $identity 路由器选中的可信接口身份
   * @return void
   */
  public function freezeCapturePolicy(RouteIdentity $identity): void
  {
    if ($this->isClosed || $this->isPolicyFrozen) { return; }
    // 即使读取失败，也固定入口策略，禁止内部分发再次覆盖外层身份。
    $this->isPolicyFrozen = true;
    try {
      $this->capturePolicy = $this->runtime?->resolve($identity)
        ?? new CapturePolicy($this->capturePolicy->state, $identity);
    } catch (\Throwable $error) { ObservationFailure::report($error); }
    // 只有路由策略固定后才能回收，路由前发布新规则仍需原安全上界。
    $this->budget->shrink('capture', CaptureBudget::forPolicy($this->capturePolicy));
  }

  /**
   * 路由失败或预检时固定入口全局策略并回收空余预算
   *
   * 不重新读取共享阈值；已固定的外层身份不能被嵌套失败覆盖。
   *
   * @return void
   */
  public function freezeGlobalPolicy(): void
  {
    if ($this->isClosed || $this->isPolicyFrozen) { return; }
    $this->isPolicyFrozen = true;
    $this->budget->shrink('capture', CaptureBudget::forPolicy($this->capturePolicy));
  }

  /**
   * 构建交给存储方的类型化快照
   *
   * 先按固定策略筛选，再将已计入预算的采集来源交付给 writer。
   *
   * @param int|null $finishedAt 测试可注入的结束时钟
   * @return SlowRequestSnapshot|null 慢请求快照，未达到阈值返回 null
   */
  public function snapshot(?int $finishedAt = null): ?SlowRequestSnapshot
  {
    $record = $this->finish($finishedAt);
    return $record === null ? null : new SlowRequestSnapshot($record, $this->operationTypes);
  }

  /**
   * 生成内部数组定稿
   *
   * 仅供采集器预算核验及测试使用；存储交付使用 snapshot() 的类型化契约。
   *
   * @param int|null $finishedAt 结束时钟（单调时钟），供确定性测试注入
   * @return array<string,mixed>|null 耗时达到阈值的完整记录；未达到阈值返回 null
   */
  public function finish(?int $finishedAt = null): ?array
  {
    $durationMs = (($finishedAt ?? hrtime(true)) - $this->startedAt) / 1e6;
    if ($durationMs < $this->capturePolicy->effectiveThresholdMs) {
      return null;
    }
    return [
      'request_id' => $this->requestId,
      'timestamp' => $this->timestamp,
      'duration_ms' => $durationMs,
      'response_time_ms' => $this->responseCapture->elapsedMs($this->startedAt),
      'request' => $this->request, 'response' => $this->responseCapture->snapshot(),
      'operations' => $this->operations, 'dropped_operations' => $this->droppedOperations,
      'exception' => $this->exception,
      'capture' => $this->capturePolicy->describe($durationMs),
      'schema_version' => 2, 'operation_summary' => $this->summary->snapshot(),
      'snapshot_truncated' => $this->budget->hasTruncated(),
    ];
  }

}
