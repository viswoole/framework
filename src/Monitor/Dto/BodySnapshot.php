<?php

declare(strict_types=1);

/* 职责：独立表达正文内容、字节数及隐藏状态；正文类型开放，元数据类型固定。 */

namespace Viswoole\Monitor\Dto;

/** 正文快照；合法 JSON 标量和数组以 content 联合类型表达，省略状态不再占用正文。 */
final readonly class BodySnapshot extends SnapshotRecord
{
  public array|string|int|float|bool|null $content;
  public int $bytes;
  public string $contentType;
  public string $omittedReason;
  public bool $isSizeKnown;
  public CaptureState $state;
  public bool $isRedacted;
  public bool $isTruncated;

  /**
   * 构建正文及其采集状态
   *
   * @param mixed $value 已脱敏正文摘要或整组标记
   */
  public function __construct(mixed $value)
  {
    parent::__construct(['value' => $value]);
    $safe = $this->toArray()['value'];
    $content = is_array($safe) ? ($safe['content'] ?? null) : $safe;
    $state = $this->resolveState($safe, $content);
    $this->state = $state;
    $this->isRedacted = $state === CaptureState::REDACTED;
    $this->isTruncated = $state === CaptureState::TRUNCATED;
    $this->isSizeKnown = is_array($safe) && ($safe['size_known'] ?? isset($safe['bytes']));
    $this->contentType = is_string($safe['content_type'] ?? null) ? $safe['content_type'] : '';
    $this->omittedReason = is_string($safe['omitted_reason'] ?? null) ? $safe['omitted_reason'] : '';
    $this->bytes = $this->isSizeKnown ? (int)$safe['bytes'] : 0;
    $this->content = $state === CaptureState::CAPTURED ? $content : null;
  }

  /**
   * 解析正文采集状态
   *
   * 显式占位符判定（非子串启发式）：业务字符串 "RATE LIMIT" 不能被误判为
   * 截断；数组包裹的合法零值标量内容（0/""/null/false）属于已捕获数据。
   *
   * @param mixed $safe 已复制的正文摘要（数组或整组占位标记）
   * @param mixed $content 正文内容
   * @return CaptureState 明确的采集状态
   */
  private function resolveState(mixed $safe, mixed $content): CaptureState
  {
    if (!is_array($safe)) { return CaptureState::resolve($safe); }
    return match (true) {
      // 顶层 truncated 标记（BODY_LIMIT / SNAPSHOT_LIMIT / 兜底截断）优先
      ($safe['truncated'] ?? false) => CaptureState::TRUNCATED,
      // 内容数组的 __truncated 是 sanitize 局部截断，同样不能伪装成完整捕获
      is_array($content) && ($content['__truncated'] ?? false) => CaptureState::TRUNCATED,
      is_string($content) => CaptureState::fromPlaceholder($content) ?? CaptureState::CAPTURED,
      default => CaptureState::CAPTURED,
    };
  }
}
