<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Http\Request;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/** 职责：验证请求正文复用已解析策略，保留旧无参快照回调的调用契约。 */
final class RequestSanitizerReuseTest extends TestCase
{
  /** @return void 清理请求状态。 */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /** @return void 请求正文与后续采集必须共享同一策略实例。 */
  public function testRequestCaptureReceivesTraceSanitizer(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0,
      'sensitive_keys_by_path' => ['/private/*' => ['code']]]);
    $request = $this->createMock(Request::class);
    $request->server = ['path_info' => '/private/login'];
    $request->header = ['content-type' => 'application/json'];
    $request->expects(self::once())->method('rawContent')->willReturn('{"code":"private","ok":true}');
    $received = null;
    $monitor = new SlowRequestMonitor($options, new InMemoryTraceWriter());
    $monitor->start('/private/login', static function (PayloadSanitizer $sanitizer) use ($options, $request, &$received): array {
      $received = $sanitizer;
      $capture = new RequestCapture($options, $sanitizer);
      self::assertSame($sanitizer, (new ReflectionProperty(RequestCapture::class, 'sanitizer'))->getValue($capture));
      return $capture->capture($request);
    }, canReceiveSanitizer: true);
    $trace = TraceContext::current();
    self::assertNotNull($trace);
    self::assertSame($received, (new ReflectionProperty(RequestTrace::class, 'sanitizer'))->getValue($trace));
    self::assertSame(['code' => '[REDACTED]', 'ok' => true], $trace->snapshot()->request->body->content);
    $monitor->finish();
  }

  /** @return void 旧契约允许无参内置 callable，不能突然传入额外参数。 */
  public function testLegacySnapshotStillReceivesNoArguments(): void
  {
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true]), new InMemoryTraceWriter());
    $monitor->start('/legacy', get_loaded_extensions(...));
    self::assertNotNull(TraceContext::current());
    $monitor->finish();
  }

  /** @return void 非结构化请求不读取正文，沿用独立调用方式。 */
  public function testUnstructuredBodyDoesNotNeedInjectedSanitizer(): void
  {
    $request = $this->createMock(Request::class);
    $request->server = ['path_info' => '/upload'];
    $request->header = ['content-type' => 'application/octet-stream'];
    $request->expects(self::never())->method('rawContent');
    $capture = new RequestCapture(new MonitorOptions());
    self::assertSame('unsupported_content_type', $capture->capture($request)['body']['omitted_reason']);
  }
}
