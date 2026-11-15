<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

use Closure;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/** 职责：验证并安全调用正文裁剪处理器；脱敏、预算及请求状态由采集层负责。 */
final readonly class BodyTruncator
{
  private ?Closure $handler;
  public bool $isEnabled;

  /**
   * 启动期验证 PHP callable。
   * @param mixed $handler 用户提供的处理器或 null
   */
  public function __construct(mixed $handler = null)
  {
    if ($handler !== null && !is_callable($handler)) {
      throw new InvalidArgumentException('monitor.body_truncator 必须是 PHP callable 或 null');
    }
    $this->isEnabled = $handler !== null;
    $this->handler = $handler === null ? null : Closure::fromCallable($handler);
  }

  /**
   * 提取业务摘要；异常仅告警，不中断业务响应和其他监控字段采集。
   * @param string $body 超限时仍可取得的原文，可能是不完整前缀
   * @param array{content_type:string,is_complete:bool,max_bytes:int,path:string,method:string,direction:string} $context 采集上下文
   * @return array|null 待脱敏的结构化摘要，null 表示使用默认占位
   */
  public function summarize(string $body, array $context): ?array
  {
    if ($this->handler === null) { return null; }
    try {
      $summary = ($this->handler)($body, $context);
      if ($summary !== null && !is_array($summary)) {
        throw new UnexpectedValueException('正文裁剪处理器必须返回数组或 null');
      }
      return $summary;
    } catch (Throwable $error) {
      // 不输出原始异常消息，避免业务凭据随解析错误进入告警。
      trigger_error('慢请求监控正文裁剪失败: ' . $error::class, E_USER_WARNING);
      return null;
    }
  }
}
