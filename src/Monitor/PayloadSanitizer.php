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
  /** @var list<string> 当前请求路径的有效字段，不修改全局配置 */
  private array $sensitiveKeys;
  /**
   * 构造脱敏器
   *
   * @param MonitorOptions $options 采集配置，提供敏感字段规则与正文上限
   * @param string $path 当前请求原始路径
   * @param PathPolicyMatcher|null $matcher 已解析的同一路径匹配器，避免重复解码
   */
  public function __construct(
    private MonitorOptions $options,
    string $path = '/',
    ?PathPolicyMatcher $matcher = null
  )
  {
    $this->sensitiveKeys = $options->sensitiveKeysForPath($path, $matcher);
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
   * @return bool 归一化后包含任一敏感词时返回 true
   */
  public function isSensitiveField(string $key): bool
  {
    // PDO 允许命名绑定键携带前导冒号，短敏感词也必须按同一逻辑识别。
    $normalized = strtolower(str_replace(['-', '_'], '', ltrim($key, ':')));
    foreach ($this->sensitiveKeys as $sensitiveKey) {
      $needle = strtolower(str_replace(['-', '_'], '', $sensitiveKey));
      if ($needle === '') { continue; }
      // 短词（<4 字符，如 otp/pwd）子串误伤率过高（otp 命中 desktop），
      // 仅要求完全相等；较长敏感词仍允许包含匹配以覆盖 x-authorization 等变体
      if (strlen($needle) < 4) {
        if ($normalized === $needle) { return true; }
        continue;
      }
      if (str_contains($normalized, $needle)) {
        return true;
      }
    }
    return false;
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
   * 应用整组容器脱敏
   *
   * 输入已经完成内容脱敏与局部限额，不再次递归，避免缩短原有深度预算。
   *
   * @param string $key 容器字段名
   * @param array $value 已安全处理的容器数据
   * @return array|string 原容器或统一脱敏标记
   */
  public function redactContainer(string $key, array $value): array|string
  {
    return $this->isSensitiveField($key) ? '[REDACTED]' : $value;
  }

  /**
   * 脱敏响应正文
   *
   * 对完整结构化正文先解析后脱敏；截断正文不输出局部内容。
   *
   * @param string $body 完整正文
   * @param string $contentType MIME 类型
   * @return array{content:mixed,bytes:int,truncated:bool} 正文摘要
   */
  public function sanitizeBody(string $body, string $contentType = ''): array
  {
    $bytes = strlen($body);
    if (!BodyContentType::canCapture($contentType)) { return BodyContentType::omitted($contentType, $bytes); }
    // 空正文（204、空 JSON 响应）不是解析失败，避免记录误导性的不可解析标记
    if ($bytes === 0) { return ['content' => null, 'bytes' => 0, 'truncated' => false]; }
    if ($bytes > min($this->options->maxBodyBytes, intdiv($this->options->maxSnapshotBytes, 4))) {
      return ['content' => '[BODY_LIMIT]', 'bytes' => $bytes, 'truncated' => true];
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
}
