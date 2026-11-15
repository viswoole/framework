<?php

declare(strict_types=1);

/* 职责：验证正文处理器获取真实请求身份与方向，不受展示字段脱敏影响。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;


/** 正文裁剪身份上下文契约。 */
final class BodyTruncationContextTest extends TestCase
{
  /**
   * 请求采集传递原始身份
   *
   * 共用脱敏器仍能区分请求正文与响应正文，查询参数不进入路径。
   *
   * @return void 断言完成
   */
  public function testRequestCaptureSuppliesIdentity(): void
  {
    $contexts = [];
    $options = new MonitorOptions(['max_body_bytes' => 30,
      'body_truncator' => static function (string $body, array $context) use (&$contexts): array {
        $contexts[] = $context;
        return ['code' => 0];
      }]);
    $request = $this->createMock(Request::class);
    $request->server = ['request_uri' => '/admin/auth/my-apis?token=private', 'request_method' => 'POST'];
    $request->header = ['content-type' => 'application/json; charset=utf-8'];
    $request->method('rawContent')->willReturn(str_repeat('x', 100));
    (new RequestCapture($options, new PayloadSanitizer($options)))->capture($request);
    self::assertSame('/admin/auth/my-apis', $contexts[0]['path']);
    self::assertSame('POST', $contexts[0]['method']);
    self::assertSame('request', $contexts[0]['direction']);
    self::assertSame('application/json', $contexts[0]['content_type']);
  }

  /**
   * 响应身份独立于展示脱敏
   *
   * 原始路径和方法仅传给处理器，日志展示仍遵循脱敏规则。
   *
   * @return void 断言完成
   */
  public function testResponseIdentitySurvivesDisplayRedaction(): void
  {
    $contexts = [];
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0, 'max_body_bytes' => 30,
      'sensitive_keys' => ['path', 'method'],
      'body_truncator' => static function (string $body, array $context) use (&$contexts): array {
        $contexts[] = $context;
        return ['code' => 0];
      }]);
    $monitor = new SlowRequestMonitor($options, new InMemoryTraceWriter());
    $monitor->start('/admin/auth/my-apis', static fn (): array => ['method' => 'GET']);
    try {
      $trace = TraceContext::current();
      $trace->updateResponse(['content_type' => 'application/json']);
      $trace->appendResponse(str_repeat('x', 100), true);
      $record = $trace->finish();
      self::assertSame('[REDACTED]', $record['request']['path']);
      self::assertSame('[REDACTED]', $record['request']['method']);
      self::assertSame('/admin/auth/my-apis', $contexts[0]['path']);
      self::assertSame('GET', $contexts[0]['method']);
      self::assertSame('response', $contexts[0]['direction']);
    } finally {
      TraceContext::clear();
    }
  }

  /**
   * 并发独立的采集身份
   *
   * 请求身份留在各自采集器，不写入共享的配置或脱敏器。
   *
   * @return void 断言完成
   */
  public function testSharedSanitizerDoesNotMixRequestIdentities(): void
  {
    $contexts = [];
    $options = new MonitorOptions(['max_body_bytes' => 20, 'body_truncator' =>
      static function (string $body, array $context) use (&$contexts): array {
        $contexts[] = $context;
        return ['code' => 0];
      }]);
    $sanitizer = new PayloadSanitizer($options);
    foreach ([['path' => '/first', 'method' => 'GET'], ['path' => '/second', 'method' => 'POST']] as $request) {
      $trace = new RequestTrace($options, $request, sanitizer: $sanitizer);
      $trace->updateResponse(['content_type' => 'application/json']);
      $trace->appendResponse(str_repeat('x', 100), true);
    }
    self::assertSame(['/first', '/second'], array_column($contexts, 'path'));
    self::assertSame(['GET', 'POST'], array_column($contexts, 'method'));
  }
}
