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

use Viswoole\Monitor\Http\BodyContentType;
use Viswoole\Monitor\Privacy\Context;
use Viswoole\Monitor\Privacy\Fields;
use Viswoole\Monitor\Privacy\Settings;
use Viswoole\Monitor\Privacy\Target;

/**
 * 数据脱敏与大小限制
 *
 * 职责：对进入记录前的任意值做递归脱敏（敏感字段名命中即替换）、
 * 深度/节点/字节限制，以及对响应正文按内容类型解析后脱敏。
 * 边界：不调用用户对象的序列化方法，避免触发业务副作用；截断正文
 * 不输出局部内容，防止不完整 JSON 绕过脱敏。
 */
final readonly class PayloadSanitizer
{
  /** @var Settings 当前请求及分组的只读保护设置 */
  private Settings $settings;
  private Context $context;
  private PathPolicyMatcher $matcher;
  private string $path;
  /**
   * 构造脱敏器
   *
   * @param MonitorOptions $options 采集配置，提供敏感字段规则与正文上限
   * @param string|Context $path 原始请求路径或明确分组的只读上下文
   * @param PathPolicyMatcher|null $matcher 已解析的同一路径匹配器，避免重复解码
   */
  public function __construct(
    private MonitorOptions $options,
    string|Context $path = '/',
    ?PathPolicyMatcher $matcher = null
  )
  {
    $this->context = is_string($path) ? new Context($path) : $path;
    $this->path = $this->context->path;
    $this->matcher = $matcher ?? new PathPolicyMatcher($this->path, $options->isRouteCaseSensitive);
    $this->settings = $options->privacy->resolve($this->context, $this->matcher);
  }

  /**
   * 脱敏单个命名字段，字段名不占用其内容的采集预算。
   * @param string $key 字段名
   * @param mixed $value 字段内容
   * @return mixed 脱敏值或有界内容
   */
  public function sanitizeField(string $key, mixed $value): mixed
  {
    return $this->isSensitiveField($key) ? '[REDACTED]' : $this->sanitize($value);
  }

  /**
   * 判断字段名是否命中敏感规则
   *
   * @param string $key 字段名
   * @return bool 归一化完整字段名命中时返回 true
   */
  public function isSensitiveField(string $key): bool
  {
    return isset($this->settings->fields[Fields::normalize($key)]);
  }

  /**
   * 对任意值做有界脱敏
   *
   * 限制递归深度、元素数和字符串长度，避免日志采集无限扩展。
   *
   * @param mixed $value 原始值
   * @param int $depth 当前深度
   * @return mixed 可安全编码的日志值
   */
  public function sanitize(mixed $value, int $depth = 0): mixed
  {
    if (!$this->canCapture()) { return '[CAPTURE_DISABLED]'; }
    // 字节预算至少 4 KiB，保证 max_body_bytes=0 时元数据仍可采集
    $budget = ['bytes' => min(
      max(4096, $this->options->maxBodyBytes),
      intdiv($this->options->maxSnapshotBytes, 4)
    ), 'nodes' => 512];
    return $this->normalize($value, $budget, $depth);
  }

  /**
   * 递归归一化任意值
   *
   * @param mixed $value 原始值
   * @param array $budget 本次调用共享的字节与节点预算（引用递减）
   * @param int $depth 深度
   * @return mixed 标量原样输出、对象替换为类型占位、数组逐层脱敏
   */
  private function normalize(mixed $value, array &$budget, int $depth): mixed
  {
    // 超过深度或节点预算直接截断，防止循环引用与超大结构拖垮请求
    if ($depth >= 8 || --$budget['nodes'] < 0) {
      return '[DEPTH_OR_NODE_LIMIT]';
    }
    if (is_float($value) && !is_finite($value)) {
      return '[NON_FINITE]';
    }
    if (is_string($value)) {
      return $this->limitString($value, $budget);
    }
    if (!is_array($value)) {
      return is_scalar($value) || $value === null ? $value : '[' . get_debug_type($value) . ']';
    }
    $result = [];
    // 单层最多采集 100 个字段，数组键原样保留
    foreach (array_slice($value, 0, 100, true) as $key => $item) {
      if ($budget['nodes'] <= 0 || $budget['bytes'] <= 0) {
        $result['__truncated'] = true;
        break;
      }
      $safeKey = is_string($key) ? $this->limitString($key, $budget) : $key;
      $result[$safeKey] = $this->isSensitiveField((string)$key)
        ? '[REDACTED]' : $this->normalize($item, $budget, $depth + 1);
    }
    if (count($value) > 100) {
      $result['__truncated'] = true;
    }
    return $result;
  }

  /**
   * 按字节预算截断字符串
   *
   * @param string $value 原始字符串
   * @param array $budget 共享字节预算（引用递减）
   * @return string 有界字符串，截断时追加 [TRUNCATED] 标记
   */
  private function limitString(string $value, array &$budget): string
  {
    $length = min(strlen($value), $budget['bytes']);
    $budget['bytes'] -= $length;
    // 字节截断可能切开 UTF-8 字符，替换非法片段以保证第三方写入器可编码。
    $limited = $length < strlen($value) ? substr($value, 0, $length) . '[TRUNCATED]' : $value;
    if (preg_match('//u', $limited) === 1) {
      return $limited;
    }
    // 替换后编码失败（理论上不可达）时返回空串兜底，保证返回类型仍为 string
    $fixed = json_decode(json_encode($limited, JSON_INVALID_UTF8_SUBSTITUTE), true);
    return is_string($fixed) ? $fixed : '';
  }

  /**
   * 派生明确作用域的脱敏器
   *
   * 请求身份与匹配器只读共享，作用域不写回当前实例，避免跨方向污染。
   *
   * @param Target $target 目标分组
   * @param string|null $method 请求方法，为 null 时复用已知方法
   * @return self 当前请求指定分组的独立脱敏器
   */
  public function forScope(Target $target, ?string $method = null): self
  {
    return new self($this->options, new Context($this->path, $method ?? $this->context->method, $target), $this->matcher);
  }

  /**
   * 判断当前分组是否允许采集
   *
   * capture 只影响明确分组，与 redact_fields 中的业务字段名无关。
   *
   * @return bool 有效隐私规则允许采集
   */
  public function canCapture(): bool
  {
    return $this->settings->canCapture;
  }

  /**
   * 判断是否需要为超限正文保留处理器输入
   *
   * 无处理器或关闭采集时直接省略，避免为最终丢弃的内容额外复制大段正文。
   *
   * @return bool 当前请求策略允许执行已配置的裁剪处理器
   */
  public function canTruncateBody(): bool
  {
    return $this->options->bodyTruncator->isEnabled && $this->canCapture();
  }

  /**
   * 生成有界的自定义正文摘要，原始正文绝不直接进入快照。
   * @param string $body 超限时仍可取得的正文或前缀
   * @param array{content_type:string,is_complete:bool,path?:string,method?:string,direction?:string} $context 正文类型、完整性及请求身份
   * @return array{content:mixed,bytes:int,truncated:bool} 已脱敏摘要或默认占位
   */
  public function truncateBody(string $body, array $context): array
  {
    $context = $this->resolveBodyContext($context);
    $scope = $this->resolveBodyScope($context);
    if ($scope !== $this) { return $scope->truncateBody($body, $context); }
    if (!$this->canCapture()) { return ['content' => '[CAPTURE_DISABLED]', 'bytes' => strlen($body), 'truncated' => false]; }
    $limit = $this->options->bodyLimit();
    $content = '[BODY_LIMIT]';
    // 关闭采集时不向用户处理器暴露原文，也不支付其解析成本。
    if ($this->canTruncateBody()) {
      $summary = $this->options->bodyTruncator->summarize($body, array_replace($context, ['max_bytes' => $limit]));
      if ($summary !== null) {
        $safe = $this->sanitize($summary);
        if (SnapshotBudget::size($safe) <= $limit) { $content = $safe; }
      }
    }
    return ['content' => $content, 'bytes' => strlen($body), 'truncated' => true];
  }

  /**
   * 脱敏响应正文
   *
   * 对完整结构化正文先解析后脱敏；截断正文不输出局部内容。
   *
   * @param string $body 完整正文
   * @param string $contentType MIME 类型
   * @param array{path?:string,method?:string,direction?:string} $context 默认继承当前方向和方法，允许显式覆盖
   * @return array{content:mixed,bytes:int,truncated:bool} 正文摘要
   */
  public function sanitizeBody(string $body, string $contentType = '', array $context = []): array
  {
    $context = $this->resolveBodyContext($context);
    $scope = $this->resolveBodyScope($context);
    if ($scope !== $this) { return $scope->sanitizeBody($body, $contentType, $context); }
    $bytes = strlen($body);
    if (!$this->canCapture()) { return ['content' => '[CAPTURE_DISABLED]', 'bytes' => $bytes, 'truncated' => false]; }
    if (!BodyContentType::canCapture($contentType)) { return BodyContentType::omitted($contentType, $bytes); }
    // 空正文（204、空 JSON 响应）不是解析失败，避免记录误导性的不可解析标记
    if ($bytes === 0) { return ['content' => null, 'bytes' => 0, 'truncated' => false]; }
    if ($bytes > $this->options->bodyLimit()) {
      return $this->truncateBody($body, array_replace($context, [
        'content_type' => BodyContentType::normalize($contentType) === 'application/x-www-form-urlencoded'
          ? 'application/x-www-form-urlencoded' : 'application/json',
        'is_complete' => true,
      ]));
    }
    // 表单先按其声明类型解析，不将合法 JSON 形状的表单误识别成 JSON。
    if (BodyContentType::normalize($contentType) === 'application/x-www-form-urlencoded') {
      parse_str($body, $fields);
      $content = $this->sanitize($fields);
    } else {
      $decoded = json_decode($body, true);
      $content = json_last_error() === JSON_ERROR_NONE ? $this->sanitize($decoded) : '[UNPARSEABLE_BODY]';
    }
    return ['content' => $content, 'bytes' => $bytes, 'truncated' => false];
  }

  /**
   * 补齐正文身份上下文
   *
   * 默认继承当前请求方向和方法，只有显式上下文才能覆盖，避免关闭采集被默认响应策略绕过。
   *
   * @param array $context 调用方显式提供的上下文
   * @return array 已包含路径、方法与正文方向的上下文
   */
  private function resolveBodyContext(array $context): array
  {
    return array_replace([
      'path' => $this->path,
      'method' => $this->context->method,
      'direction' => str_starts_with($this->context->target->value, 'request.') ? 'request' : 'response',
    ], $context);
  }

  /**
   * 解析最终正文作用域
   *
   * 两个正文入口共用同一解析规则，覆盖方法或方向后重新选择策略，不改变原实例。
   *
   * @param array{method:string,direction:string} $context 已补齐的正文身份
   * @return self 最终正文策略，身份一致时复用当前实例
   */
  private function resolveBodyScope(array $context): self
  {
    $target = $context['direction'] === 'request' ? Target::REQUEST_BODY : Target::RESPONSE_BODY;
    return $this->context->target === $target && $this->context->method === $context['method']
      ? $this : $this->forScope($target, $context['method']);
  }

}
