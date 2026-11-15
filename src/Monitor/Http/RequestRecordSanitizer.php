<?php

declare(strict_types=1);

/* 职责：按请求分组清洗快照，防止正文策略被 post 副本绕过，不读取 HTTP 对象。 */

namespace Viswoole\Monitor\Http;

use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\Privacy\Target;

/** 请求快照的分组脱敏入口；已解析正文不重复递归清洗。 */
final readonly class RequestRecordSanitizer
{
  /**
   * 绑定当前请求的只读脱敏器
   *
   * 具体作用域在处理各分组时派生，不污染其他方向。
   *
   * @param PayloadSanitizer $sanitizer 当前请求元数据脱敏器
   */
  public function __construct(private PayloadSanitizer $sanitizer) {}

  /**
   * 清洗请求分组与基础元信息
   *
   * 容器名称不参与字段脱敏，body 与 post 共享正文策略。
   *
   * @param array $request 待定稿请求快照
   * @return array 已安全清洗的独立快照
   */
  public function sanitize(array $request): array
  {
    $groups = [];
    foreach (['headers' => Target::REQUEST_HEADERS, 'query' => Target::REQUEST_QUERY,
      'post' => Target::REQUEST_BODY, 'body' => Target::REQUEST_BODY] as $name => $target) {
      if (!array_key_exists($name, $request)) { continue; }
      $scope = $this->sanitizer->forScope($target);
      $value = $request[$name];
      unset($request[$name]);
      $groups[$name] = $this->sanitizeGroup($name, $value, $scope);
    }
    return $this->sanitizer->sanitize($request) + $groups;
  }

  /**
   * 清洗单个请求分组
   *
   * 正文已包含安全 content 与 bytes 时只保留结构；关闭采集优先于任何数据。
   *
   * @param string $name 分组名称
   * @param mixed $value 分组原始值
   * @param PayloadSanitizer $scope 当前分组策略
   * @return mixed 安全分组或未采集标记
   */
  private function sanitizeGroup(string $name, mixed $value, PayloadSanitizer $scope): mixed
  {
    if (!$scope->canCapture()) {
      return $name === 'body'
        ? ['content' => '[CAPTURE_DISABLED]', 'bytes' => 0, 'size_known' => false, 'truncated' => false]
        : '[CAPTURE_DISABLED]';
    }
    if ($name === 'body' && is_array($value) && array_key_exists('content', $value) && array_key_exists('bytes', $value)) {
      return $value;
    }
    if ($name === 'headers' && is_array($value)) { $value = RequestPrivacy::sanitizeHeaders($value); }
    return $scope->sanitize($value);
  }
}
