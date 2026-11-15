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

namespace Viswoole\Monitor\Http;

use Swoole\Http\Request;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\Privacy\Target;

/**
 * Swoole 原始请求采集器
 *
 * 职责：将 Swoole 原始请求读取为可脱敏的请求元数据数组。
 * 边界：不读取上传文件内容（仅采集字段名），不保留原始查询串；
 * 采集时机由调用方决定（仅在确认需要采集后才调用）。
 * 与 Dto\RequestSnapshot（存储侧定稿 DTO）职责不同，命名刻意区分。
 */
final readonly class RequestCapture
{
  /**
   * 构造快照采集器
   *
   * @param MonitorOptions $options 采集配置
   * @param PayloadSanitizer|null $sanitizer 同一请求已解析的脱敏器，独立使用时按需创建
   */
  public function __construct(private MonitorOptions $options, private ?PayloadSanitizer $sanitizer = null) {}

  /**
   * 提取用于策略匹配的请求路径
   *
   * 统一 startMonitor 与 capture 的路径提取，避免两处逻辑漂移；
   * path_info 缺失时 request_uri 可能携带 query string，需剥离后仅保留路径，
   * 否则排除策略的 fnmatch 会因 query 失配。
   *
   * @param Request $request 原始请求
   * @return string 纯路径（不含 query string），无法解析时回退为 /
   */
  public static function extractPath(Request $request): string
  {
    $pathInfo = $request->server['path_info'] ?? null;
    // path_info 已是路径，不能把 // 解释成 authority，也不能移除字面量 ?/#。
    if (is_string($pathInfo) && $pathInfo !== '') { return $pathInfo; }
    $rawPath = $request->server['request_uri'] ?? '/';
    if (!is_string($rawPath) || $rawPath === '') { return '/'; }
    $path = explode('?', $rawPath, 2)[0];
    return $path !== '' ? $path : '/';
  }

  /**
   * 读取原始请求快照
   *
   * @param Request $request 原始请求
   * @return array 请求元数据（方法、路径、Host、IP、头、参数、正文、上传字段名）
   */
  public function capture(Request $request): array
  {
    $headers = $request->header ?? [];
    $path = self::extractPath($request);
    if (!$this->options->captureRequest) {
      return [
        'method' => $request->server['request_method'] ?? '',
        'path' => $path,
      ];
    }
    $context = ['path' => $path, 'method' => (string)($request->server['request_method'] ?? ''), 'direction' => 'request'];
    $sanitizer = ($this->sanitizer ?? new PayloadSanitizer($this->options, $path))->forScope(Target::REQUEST_BODY, $context['method']);
    return [
      'method' => $request->server['request_method'] ?? '',
      'path' => $path,
      'host' => $headers['host'] ?? '',
      'ip' => $request->server['remote_addr'] ?? '',
      'headers' => $sanitizer->forScope(Target::REQUEST_HEADERS)->canCapture() ? $headers : '[CAPTURE_DISABLED]',
      'query' => $sanitizer->forScope(Target::REQUEST_QUERY)->canCapture() ? ($request->get ?? []) : '[CAPTURE_DISABLED]',
      'post' => $sanitizer->canCapture() ? ($request->post ?? []) : '[CAPTURE_DISABLED]',
      'body' => $this->captureBody($request, $sanitizer, $context),
      'files' => array_keys($request->files ?? []),
    ];
  }

  /**
   * 按策略读取正文
   *
   * 采集关闭或类型不支持时不调用 rawContent，也不执行裁剪处理器。
   *
   * @param Request $request 原始 HTTP 请求
   * @param PayloadSanitizer $sanitizer 已解析请求正文策略的脱敏器
   * @param array $context 请求身份及方向
   * @return array 正文快照或未采集占位
   */
  private function captureBody(Request $request, PayloadSanitizer $sanitizer, array $context): array
  {
    if (!$sanitizer->canCapture()) {
      return ['content' => '[CAPTURE_DISABLED]', 'bytes' => 0, 'size_known' => false, 'truncated' => false];
    }
    $type = $request->header['content-type'] ?? '';
    if (!BodyContentType::canCapture($type)) { return BodyContentType::omitted($type, null); }
    $body = $request->rawContent();
    return $sanitizer->sanitizeBody($body === false ? '' : $body, $type, $context);
  }

}
