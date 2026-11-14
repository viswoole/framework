<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Viswoole\HttpServer\Response;
use Viswoole\Monitor\Http\UriSanitizer;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/** 验证 URL 清理只影响日志，直接标头与快捷跳转共用隐私边界。 */
final class LocationPrivacyTest extends TestCase
{
  /** @return void 清理请求状态，避免污染其他测试。 */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /** @return void 相对、绝对及畸形 URL 都不能保留 URL 内嵌凭据。 */
  public function testUriFormsRemoveCredentials(): void
  {
    $cases = [
      '/callback?token=private#private' => '/callback',
      'https://user:private@example.com:8443/callback?token=private#private' => 'https://example.com:8443/callback',
      '//user:private@example.com/callback?code=private' => '//example.com/callback',
      'https://[::1]:8443/callback?code=private' => 'https://[::1]:8443/callback',
      '?token=private' => '', '#private' => '',
      'https://user:private@example.com:99999/callback' => '[INVALID_URI]',
      'custom:private?token=private' => 'custom:[OMITTED]',
    ];
    foreach ($cases as $uri => $expected) {
      self::assertSame($expected, UriSanitizer::sanitize($uri));
    }
  }

  /** @return void 原生响应收到完整地址，快照和增量采集仅保存清理后的地址。 */
  public function testDirectHeaderDoesNotChangeBusinessLocation(): void
  {
    $uri = 'https://user:private@example.com/callback?token=private#private';
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('status')->willReturn(true);
    $raw->method('header')->willReturnCallback(static function (string $key, string $value) use ($uri): bool {
      if (strtolower($key) === 'location') { self::assertSame($uri, $value); }
      return true;
    });
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    (new Response($raw))->header('LoCaTiOn', $uri);
    self::assertSame('https://example.com/callback', $trace->finish()['response']['headers']['location']);
    $trace->updateResponse(['headers' => ['Location' => $uri]]);
    self::assertStringNotContainsString('private', json_encode($trace->finish(), JSON_THROW_ON_ERROR));
  }

  /** @return void URL 清理后仍须执行自定义字段脱敏。 */
  public function testLocationFieldCanStillBeFullyRedacted(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys' => ['location']]), []);
    $trace->updateResponseHeader('Location', '/callback?token=private');
    self::assertSame('[REDACTED]', $trace->finish()['response']['headers']['location']);
  }

  /** @return void 快捷跳转复用 URL 清理且不改变实际跳转地址。 */
  public function testRedirectUsesTheSameSanitizer(): void
  {
    $uri = '//user:private@example.com/callback?token=private';
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('status')->willReturn(true);
    $raw->method('header')->willReturn(true);
    $raw->expects(self::once())->method('redirect')->with($uri, 302)->willReturn(true);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    self::assertTrue((new Response($raw))->redirect($uri));
    self::assertSame('//example.com/callback', $trace->finish()['response']['redirect']);
    self::assertTrue($trace->finish()['response']['completed']);
  }
}
