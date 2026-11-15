<?php

/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Monitor;

use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use Viswoole\Monitor\Http\RequestPrivacy;
use Viswoole\Monitor\Http\RequestRecordSanitizer;
use Viswoole\Monitor\Privacy\Target;

/**
 * 单个请求的有界采集状态
 *
 * 职责：按预算收集请求元数据、数据库/缓存操作与响应摘要，并在请求结束时
 * 输出达到阈值的慢请求记录。
 * 边界：仅负责采集与脱敏，不负责持久化（由 TraceWriterInterface 承担）；
 * 实例只能存放在请求协程上下文中（见 TraceContext），协程间互不共享。
 */
final class RequestTrace
{
  /** @var int 请求采集开始时钟（hrtime 单调时钟，纳秒），所有耗时以此计基准 */
  public readonly int $startedAt;
  /** @var string 请求关联标识；默认随机存在碰撞概率，严格唯一由自定义生成器负责 */
  public readonly string $requestId;
  /** @var string 请求开始时间（ISO-8601，应用时区） */
  public readonly string $timestamp;
  /** @var array 已脱敏的请求元数据（方法、路径、头、参数、正文等） */
  private array $request;
  /** @var array<int,array> 按开始顺序存放的操作记录，超限后不再分配 */
  private array $operations = [];
  /** @var array<int,OperationType> 与已保留明细一一对应的可信分类，不受输出脱敏影响 */
  private array $operationTypes = [];
  private readonly SnapshotBudget $budget;
  private readonly OperationSummary $summary;
  private bool $isClosed = false;
  /** @var int 达到操作条数或预算上限后被省略的操作数 */
  private int $droppedOperations = 0;
  private readonly ResponseCapture $responseCapture;
  /** @var string|null 请求处理过程中捕获的异常类型，无异常为 null */
  private ?string $exception = null;
  /** @var PayloadSanitizer 脱敏器，构造时创建并贯穿整个请求生命周期 */
  private readonly PayloadSanitizer $sanitizer;
  /** @var array{path:string,method:string} 独立于展示脱敏的请求身份，仅用于正文处理器 */
  private readonly array $bodyContext;

  /**
   * 构造时完成请求元数据的采集与脱敏
   *
   * @param MonitorOptions $options 采集配置
   * @param array $request 请求元数据（由 RequestSnapshot::capture 提供）
   * @param int|null $startedAt 请求采集开始时钟，为 null 时取当前单调时钟
   * @param PayloadSanitizer|null $sanitizer 已解析同一路径策略的脱敏器，避免重复归一化
   */
  public function __construct(
    public readonly MonitorOptions $options,
    array $request,
    ?int $startedAt = null,
    ?PayloadSanitizer $sanitizer = null
  )
  {
    $this->startedAt = $startedAt ?? hrtime(true);
    $this->timestamp = date('c');
    $this->requestId = $options->requestId->generate();
    $this->budget = new SnapshotBudget($options->maxSnapshotBytes);
    $this->summary = new OperationSummary();
    $this->sanitizer = ($sanitizer ?? new PayloadSanitizer($options, (string)($request['path'] ?? '/')))
      ->forScope(Target::REQUEST_METADATA, (string)($request['method'] ?? ''));
    $this->bodyContext = ['path' => (string)($request['path'] ?? '/'), 'method' => (string)($request['method'] ?? '')];
    $this->responseCapture = new ResponseCapture($options, $this->budget, $this->sanitizer);
    if (!$options->captureRequest) {
      $request = array_intersect_key($request, array_flip(['method', 'path']));
    }
    $safe = $this->sanitizeRequest($request);
    // 预算不足时保留分组所需的最小字段，不能把 method/path 一起丢弃
    $this->request = $this->budget->replace('request', SnapshotBudget::size($safe))
      ? $safe : array_intersect_key($safe, array_flip(['method', 'path'])) + ['__truncated' => true];
  }

  /**
   * 清洗请求元数据，预脱敏正文不重复递归
   *
   * body 由 RequestCapture::capture 按内容类型解析并完成字段级脱敏，
   * 这里按明确分组执行采集开关，避免每请求对已脱敏正文做二次递归清洗。
   *
   * @param array $request 请求元数据（由 RequestCapture::capture 提供）
   * @return array 已脱敏且有界的请求元数据
   */
  private function sanitizeRequest(array $request): array
  {
    return (new RequestRecordSanitizer($this->sanitizer))->sanitize($request);
  }

  /**
   * 登记一次操作的开始
   *
   * 在操作开始时预留位置，保证并发完成顺序不会改变操作开始顺序。
   *
   * @param array $metadata 操作类型、SQL 或缓存键等信息
   * @return int|null 操作位置，达到采集上限后返回 null（不再分配）
   */
  public function beginOperation(array $metadata): ?int
  {
    if ($this->isClosed) {
      return null;
    }
    if (count($this->operations) >= $this->options->maxOperations) {
      $this->droppedOperations++;
      return null;
    }
    $index = count($this->operations);
    $metadata = SqlCapture::sanitize($metadata, $this->options->canRecordSql);
    $safe = $this->sanitizer->forScope(Target::OPERATION_METADATA)->sanitize(CacheKeySanitizer::sanitize($metadata));
    if (!$this->budget->replace('operation:' . $index, SnapshotBudget::size($safe) + 1024)) {
      $this->droppedOperations++;
      return null;
    }
    $this->operationTypes[$index] = OperationType::resolve($metadata['type'] ?? null);
    $this->operations[] = $safe + [
        'offset_ms' => (hrtime(true) - $this->startedAt) / 1e6,
        'duration_ms' => null, 'success' => null,
      ];
    return $index;
  }

  /**
   * 登记一次操作的结束
   *
   * @param int $index 操作位置（beginOperation 的返回值）
   * @param int $startedAt 操作开始的单调时钟
   * @param array $outcome 结果摘要（success、异常类型、结果类型等）
   */
  public function endOperation(int $index, int $startedAt, array $outcome): void
  {
    if ($this->isClosed || !isset($this->operations[$index])) {
      return;
    }
    $duration = (hrtime(true) - $startedAt) / 1e6;
    $this->summary->record([
      'type' => $this->operationTypes[$index]->value, 'duration_ms' => $duration,
      'success' => $outcome['success'] ?? false, 'dropped' => false,
    ]);
    $record = array_merge(
      $this->operations[$index],
      $this->sanitizer->forScope(Target::OPERATION_METADATA)->sanitize($outcome),
      ['duration_ms' => $duration]
    );
    if (!$this->budget->replace('operation:' . $index, SnapshotBudget::size($record) + 2)) {
      // 结果摘要异常膨胀时降级为开始信息：降级记录严格小于开始时的预留
      // （原记录 + 1024），且 replace 恒保证 usedBytes 不超上限，因此本次
      // 恢复必然成功，槽位账实保持一致。
      $record = $this->operations[$index] + ['outcome_truncated' => true];
      $record['duration_ms'] = $duration;
      $record['success'] = $outcome['success'] ?? false;
      $this->budget->replace('operation:' . $index, SnapshotBudget::size($record) + 2);
    }
    $this->operations[$index] = $record;
  }

  /** @param array $metadata 响应元数据 @return void */
  public function updateResponse(array $metadata): void
  {
    $this->responseCapture->updateResponse($metadata);
  }

  /** @param string $key 标头名 @param string $value 标头值 @return void */
  public function updateResponseHeader(string $key, string $value): void
  {
    $this->responseCapture->updateResponseHeader($key, $value);
  }

  /** @param string $content 成功发送的内容 @param bool $isComplete 是否完成 @return void */
  public function appendResponse(string $content, bool $isComplete = false): void
  {
    $this->responseCapture->appendResponse($content, $isComplete, $this->bodyContext);
  }

  /** @return void 记录首次响应完成时间。 */
  public function completeResponse(): void
  {
    $this->responseCapture->completeResponse();
  }

  /** @return void 文件传输实际字节数未知，不记录虚假的确定值。 */
  public function completeFileResponse(): void
  {
    $this->responseCapture->completeFileResponse();
  }

  /**
   * 记录请求处理过程中捕获的异常
   *
   * 异常消息可能包含 SQL 值或凭据，只记录异常类型。
   *
   * @param \Throwable $error 请求异常
   */
  public function recordException(\Throwable $error): void
  {
    if ($this->isClosed) {
      return;
    }
    $this->exception = $error::class;
  }

  /**
   * 构建交给存储方的类型化快照
   *
   * 复用既有数组定稿流程，保证 JSON 字段及总预算不因 DTO 改动而变化。
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
    if ($durationMs < $this->options->thresholdMs) {
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
      'schema_version' => 1, 'operation_summary' => $this->summary->snapshot(),
      'snapshot_truncated' => $this->budget->hasTruncated(),
    ];
  }

  /**
   * 累加省略明细的操作
   *
   * @param array{type:string,duration_ms:float,success:bool} $operation 操作完成信息
   * @return void
   */
  public function recordDroppedOperation(array $operation): void
  {
    if (!$this->isClosed) {
      $this->summary->record($operation + ['dropped' => true]);
    }
  }

  /**
   * 关闭采集状态
   *
   * 共享上下文的子协程迟到事件必须被忽略，不能继续修改已交付请求。
   *
   * @return void
   */
  public function close(): void
  {
    $this->isClosed = true;
    $this->responseCapture->close();
  }

  /**
   * 补充路由匹配信息
   *
   * @param array<string,mixed> $route 路由模板、参数与处理器标识
   * @return void
   */
  public function updateRoute(array $route): void
  {
    if ($this->isClosed) {
      return;
    }
    // 必须在移除路径参数之前保护展示路径，关闭参数采集不能绕过保护。
    unset($this->request['route']);
    $request = RequestPrivacy::redactPath($this->request, $route['parameters'] ?? [], $this->sanitizer);
    if (!$this->options->captureRequest) {
      unset($route['parameters']);
    }
    // 路由数据并入 request 单槽位计费，避免与独立槽位重复占用预算。
    $request['route'] = $this->sanitizer->sanitize($route);
    if ($this->budget->replace('request', SnapshotBudget::size($request))) {
      $this->request = $request;
      return;
    }
    // 预算不足时保留分组所需的最小字段，不能连 method/path 一起丢弃。
    $fallback = array_intersect_key($request, array_flip(['method', 'path'])) + ['__truncated' => true];
    $this->request = $this->budget->replace('request', SnapshotBudget::size($fallback))
      ? $fallback : ['__truncated' => true];
  }
}
