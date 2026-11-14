<?php

declare(strict_types=1);

/* 职责：响应元信息和有界正文；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

/**
 * 响应元信息和有界正文 DTO
 *
 * 标量保持固定类型，隐藏/缺失状态由 fieldStates 区分；0/空串不代表有效 HTTP 值。
 */
final readonly class ResponseSnapshot extends SnapshotRecord
{
  public int $status;
  public bool $completed;
  public string $contentType;
  public HeaderSnapshot $headers;
  public bool $headersTruncated;
  public BodySnapshot $body;
  public CollectionSnapshot $file;
  public string $redirect;
  /** @var array<string,CaptureState> 标量元数据的可用状态，默认值不代表已采集 */
  public array $fieldStates;

  /**
   * 构建定稿记录
   *
   * @param array<string,mixed> $data 已脱敏的记录字段
   */
  public function __construct(array $data)
  {
    parent::__construct($data);
    $safe = $this->toArray();
    $this->status = is_int($safe['status'] ?? null) ? $safe['status'] : 0;
    $this->completed = is_bool($safe['completed'] ?? null) ? $safe['completed'] : false;
    $this->contentType = is_string($safe['content_type'] ?? null) && $safe['content_type'] !== '[REDACTED]' ? $safe['content_type'] : '';
    $this->headers = new HeaderSnapshot($safe['headers'] ?? null);
    $this->headersTruncated = is_bool($safe['headers_truncated'] ?? null) ? $safe['headers_truncated'] : false;
    $this->body = new BodySnapshot($safe['body'] ?? null);
    $this->file = new CollectionSnapshot($safe['file'] ?? null);
    $this->redirect = is_string($safe['redirect'] ?? null) && $safe['redirect'] !== '[REDACTED]' ? $safe['redirect'] : '';
    $states = [];
    foreach (['status', 'completed', 'content_type', 'headers_truncated', 'redirect'] as $field) {
      $value = $safe[$field] ?? null;
      $states[$field] = $value === null ? CaptureState::NOT_CAPTURED
        : ($value === '[REDACTED]' ? CaptureState::REDACTED : CaptureState::CAPTURED);
    }
    $this->fieldStates = $states;
  }
}
