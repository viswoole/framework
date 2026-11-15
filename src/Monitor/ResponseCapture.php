<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

use Throwable;
use Viswoole\Monitor\Http\BodyContentType;
use Viswoole\Monitor\Privacy\Target;

/** 职责：有界采集响应元数据、正文及完成时间；不执行发送，也不持久化。 */
final class ResponseCapture
{
  private const int MAX_CONTENT_TYPE_BYTES = 256;
  private bool $isClosed = false;
  private bool $isBodyDropped = false;
  private bool $isBodyTypeOmitted = false;
  private bool $hasFileResponse = false;
  private string $responseBody = '';
  /** @var array|null 已脱敏的超限正文摘要，不缓存超限原文 */
  private ?array $bodySummary = null;
  private int $responseBytes = 0;
  /** @var array<string,mixed> 已脱敏的响应元数据 */
  private array $response = ['status' => 200, 'completed' => false];
  private readonly ResponseHeaders $responseHeaders;
  private readonly PayloadSanitizer $sanitizer;
  private readonly PayloadSanitizer $metadataSanitizer;
  private ?int $respondedAt = null;
  /** @var string 内部解析模式，仅为 JSON、表单或空串，不受展示字段脱敏和预算影响 */
  private string $bodyParsingType = '';

  /**
   * 共享请求总预算及有效脱敏策略，不持有真实 HTTP 响应对象
   * @param MonitorOptions $options 采集配置
   * @param SnapshotBudget $budget 请求级共享预算
   * @param PayloadSanitizer $sanitizer 请求路径的脱敏策略
   */
  public function __construct(
    private readonly MonitorOptions $options,
    private readonly SnapshotBudget $budget,
    PayloadSanitizer $sanitizer
  )
  {
    $this->sanitizer = $sanitizer->forScope(Target::RESPONSE_BODY);
    $this->metadataSanitizer = $sanitizer->forScope(Target::RESPONSE_METADATA);
    $this->responseHeaders = new ResponseHeaders($options, $budget, $sanitizer);
  }

  /**
   * 返回首次发送完成相对于请求开始的耗时
   * @param int $startedAt 请求开始时钟
   * @return float|null 未结束的响应为 null
   */
  public function elapsedMs(int $startedAt): ?float
  {
    return $this->respondedAt === null ? null : ($this->respondedAt - $startedAt) / 1e6;
  }

  /** @return void 关闭后忽略共享上下文中的迟到响应事件。 */
  public function close(): void
  {
    $this->isClosed = true;
  }

  /**
   * 更新响应摘要（状态码、标头、文件信息等）
   *
   * @param array $metadata 响应元数据，按键合并到已有摘要
   */
  public function updateResponse(array $metadata): void
  {
    if ($this->isClosed) {
      return;
    }
    try {
      // 标头快照与增量更新共用预算，分组隐私规则不改变内部采集状态的类型。
      foreach (($metadata['headers'] ?? []) as $key => $value) {
        if (!$this->options->captureResponse) { continue; }
        // Swoole 标头值允许 string|array，统一折叠为字符串，
        // 避免单个非常规标头让整次响应采集中止
        $this->responseHeaders->record(
          (string)$key,
          is_array($value) ? implode(',', array_map(strval(...), $value)) : (string)$value
        );
      }
      unset($metadata['headers']);
      $metadata = $this->prepareContentType($metadata);
      $safe = array_merge($this->response, $this->metadataSanitizer->sanitize($metadata));
      $this->retainMetadata($safe);
    } catch (Throwable $error) {
      // 监控属于旁路设施：响应采集失败只降级告警，绝不能破坏业务响应流
      trigger_error('慢请求监控响应采集失败: ' . $error::class, E_USER_WARNING);
    }
  }

  /**
   * 独立保存可信的正文解析模式，日志展示值采用固定长度上限
   * @param array<string,mixed> $metadata 尚未脱敏的响应元数据
   * @return array<string,mixed> Content-Type 展示值已限长的副本
   */
  private function prepareContentType(array $metadata): array
  {
    if (!array_key_exists('content_type', $metadata)) { return $metadata; }
    $value = is_string($metadata['content_type']) ? $metadata['content_type'] : '';
    $type = BodyContentType::normalize($value);
    // 只保存解析模式，不在内部状态中长时间保留任意长度的原始标头。
    $this->bodyParsingType = !BodyContentType::canCapture($type) ? ''
      : ($type === 'application/x-www-form-urlencoded' ? $type : 'application/json');
    $metadata['content_type'] = strlen($value) > self::MAX_CONTENT_TYPE_BYTES ? '[TRUNCATED]' : $value;
    return $metadata;
  }

  /**
   * 按真实编码大小保留元数据，降级展示值也必须重新计费
   * @param array<string,mixed> $safe 已脱敏的响应元数据
   * @return void
   */
  private function retainMetadata(array $safe): void
  {
    // 非法 UTF-8 替换可能膨胀字符串，限额以最终安全展示值为准。
    if (is_string($safe['content_type'] ?? null) && strlen($safe['content_type']) > self::MAX_CONTENT_TYPE_BYTES) {
      $safe['content_type'] = '[TRUNCATED]';
    }
    if ($this->budget->replace('response', SnapshotBudget::size($safe))) {
      $this->response = $safe;
      return;
    }
    $fallback = array_intersect_key($safe, array_flip(['status', 'completed', 'content_type']));
    if ($this->budget->replace('response', SnapshotBudget::size($fallback))) {
      $this->response = $fallback;
      return;
    }
    // 变量数据完全无法计费时，只使用固定结构预留容纳状态与完成标志。
    $this->response = array_filter(
      array_intersect_key($safe, array_flip(['status', 'completed'])),
      static fn (mixed $value): bool => is_int($value) || is_bool($value) || $value === '[REDACTED]'
    );
  }

  /**
   * 增量记录单个响应标头
   *
   * 相比整表快照，仅对单键值做脱敏，避免每设置一个标头就全量递归脱敏。
   *
   * @param string $key 标头名称
   * @param string $value 标头值
   */
  public function updateResponseHeader(string $key, string $value): void
  {
    if ($this->isClosed) {
      return;
    }
    try {
      if ($this->options->captureResponse) {
        $this->responseHeaders->record($key, $value);
      }
    } catch (Throwable $error) {
      // 采集失败仅告警，标头设置已由业务层完成，不能反向影响业务
      trigger_error('慢请求监控标头采集失败: ' . $error::class, E_USER_WARNING);
    }
  }

  /**
   * 追加一段已写出的响应正文
   *
   * @param string $content 已成功写出的内容
   * @param bool $isComplete 是否结束响应
   * @param array{path?:string,method?:string} $context 不受展示字段脱敏影响的请求身份
   */
  public function appendResponse(string $content, bool $isComplete = false, array $context = []): void
  {
    if ($this->isClosed) {
      return;
    }
    try {
      $this->doAppendResponse($content, $isComplete, $context);
    } catch (Throwable $error) {
      // 正文采集失败不影响已完成的业务发送，仅降级告警
      trigger_error('慢请求监控正文采集失败: ' . $error::class, E_USER_WARNING);
    }
  }

  /**
   * 追加响应正文的实际采集流程
   *
   * @param string $content 已成功写出的内容
   * @param bool $isComplete 是否结束响应
   * @param array{path?:string,method?:string} $context 不受展示字段脱敏影响的请求身份
   */
  private function doAppendResponse(string $content, bool $isComplete, array $context): void
  {
    $this->responseBytes += strlen($content);
    // 超限后释放已缓存的正文，既限制内存也避免输出未经完整解析的敏感内容。
    if (!$this->options->captureResponse || !$this->sanitizer->canCapture()) {
      if ($isComplete) {
        $this->completeResponse();
      }
      return;
    }
    if ($this->isBodyTypeOmitted || $this->bodyParsingType === '') {
      // 首段输出后不能重新补齐被省略的数据，后续标头改变也不恢复采集。
      $this->isBodyTypeOmitted = true;
      $this->responseBody = '';
      $this->budget->release('body');
      if ($isComplete) { $this->completeResponse(); }
      return;
    }
    $this->retainBody($content, $isComplete, $context);
    if ($isComplete) {
      $this->completeResponse();
    }
  }

  /**
   * 在释放超限原文前提取摘要；后续分段仅累计实际字节数。
   * @param string $content 本次发送片段
   * @param bool $isComplete 是否已取得完整响应
   * @param array{path?:string,method?:string} $context 当前请求身份
   * @return void
   */
  private function retainBody(string $content, bool $isComplete, array $context): void
  {
    if ($this->isBodyDropped) { return; }
    $limit = $this->options->bodyLimit();
    // 未启用处理器或关闭正文采集时，超限片段无需拼接，保持采集内存有界。
    if ($this->responseBytes > $limit && !$this->sanitizer->canTruncateBody()) {
      $this->dropBody();
      return;
    }
    $candidate = $this->responseBody . $content;
    if ($this->responseBytes <= $limit && $this->budget->replace('body', SnapshotBudget::size($candidate))) {
      $this->responseBody = $candidate;
      return;
    }
    // 先释放缓存和预算，即使用户处理器失败也不能遗留原始正文。
    $this->dropBody();
    $this->bodySummary = $this->sanitizer->truncateBody($candidate, $context + [
      'content_type' => $this->bodyParsingType, 'is_complete' => $isComplete, 'direction' => 'response',
    ]);
    if (!$this->budget->replace('body', SnapshotBudget::size($this->bodySummary))) {
      $this->bodySummary = null;
    }
  }

  /**
   * 释放正文缓存及预算
   *
   * 超限后不可恢复完整原文，统一关闭后续片段的缓存采集。
   *
   * @return void 正文已丢弃
   */
  private function dropBody(): void
  {
    $this->isBodyDropped = true;
    $this->responseBody = '';
    $this->budget->release('body');
  }

  /**
   * 标记响应完成
   *
   * 响应耗时与请求处理总耗时分别统计；重复调用以首次时间为准。
   */
  public function completeResponse(): void
  {
    if ($this->isClosed) {
      return;
    }
    $this->respondedAt ??= hrtime(true);
    $this->response['completed'] = true;
  }

  /**
   * 标记文件传输完成；不将调用参数中的长度伪装成实际已写出字节数
   * @return void
   */
  public function completeFileResponse(): void
  {
    if ($this->isClosed) { return; }
    $this->hasFileResponse = true;
    $this->responseBody = '';
    $this->budget->release('body');
    $this->completeResponse();
  }

  /**
   * 构建响应快照
   *
   * @return array 响应摘要与正文，超限正文不输出局部内容
   */
  public function snapshot(): array
  {
    $contentType = $this->response['content_type'] ?? '';
    $body = $this->sanitizer->sanitizeBody($this->responseBody, $this->bodyParsingType);
    if (!$this->options->captureResponse || !$this->sanitizer->canCapture()) {
      $body = ['content' => '[CAPTURE_DISABLED]', 'bytes' => $this->responseBytes,
        'size_known' => !$this->hasFileResponse, 'truncated' => false];
    } elseif ($this->hasFileResponse) {
      $body = BodyContentType::omitted($contentType, null);
      $body['omitted_reason'] = 'file_transfer';
    } elseif ($this->isBodyTypeOmitted || $this->bodyParsingType === '') {
      $body = BodyContentType::omitted($contentType, $this->responseBytes);
    } elseif ($this->isBodyDropped) {
      $body = $this->bodySummary ?? ['content' => '[BODY_LIMIT]', 'truncated' => true];
      $body['bytes'] = $this->responseBytes;
    }
    if (!$this->budget->replace('body', SnapshotBudget::size($body))) {
      // 预算耗尽时输出无 bytes 字段的最小占位再试一次；极端场景下该占位
      // （约数十字节）不计费，截断状态由 replace 内部的 hasTruncated 标记。
      $minimal = ['content' => '[SNAPSHOT_LIMIT]', 'truncated' => true];
      if ($this->budget->replace('body', SnapshotBudget::size($minimal))) {
        $body = $minimal;
      }
    }
    return $this->response + $this->responseHeaders->snapshot() + ['body' => $body];
  }

}
