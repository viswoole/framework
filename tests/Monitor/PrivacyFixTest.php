<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;

/** 职责：验证请求路径与标头的旁路脱敏，不改变真实请求。 */
final class PrivacyFixTest extends TestCase
{
  /** @return void 敏感路径参数不能通过原始路径重复泄漏。 */
  public function testSensitivePathIsHiddenWithEitherCaptureSetting(): void
  {
    foreach ([true, false] as $canCapture) {
      $trace = new RequestTrace(new MonitorOptions([
        'threshold_ms' => 0, 'capture_request' => $canCapture,
      ]), ['path' => '/reset/synthetic-private']);
      $trace->updateRoute(['paths' => ['/reset/{ticket}'], 'parameters' => ['ticket' => 'synthetic-private']]);
      $snapshot = $trace->snapshot();
      self::assertSame('[REDACTED]', $snapshot->request->path);
      self::assertStringNotContainsString('synthetic-private', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
  }

  /** @return void 普通动态路径仍可诊断，自定义短敏感词也保护路径。 */
  public function testPathRedactionUsesEffectiveFieldPolicy(): void
  {
    $trace = new RequestTrace(new MonitorOptions([
      'threshold_ms' => 0, 'privacy' => PrivacyOptions::paths(['/auth/*' => ['code']]),
    ]), ['path' => '/auth/synthetic-private']);
    $trace->updateRoute(['parameters' => ['code' => 'synthetic-private']]);
    self::assertSame('[REDACTED]', $trace->snapshot()->request->path);
    $public = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), ['path' => '/users/42']);
    $public->updateRoute(['parameters' => ['id' => '42']]);
    self::assertSame('/users/42', $public->snapshot()->request->path);
  }

  /** @return void URL 清理必须早于字符串截断，且不修改调用方标头。 */
  public function testRefererCredentialsAreRemoved(): void
  {
    $headers = ['ReFeReR' => 'https://user:synthetic-private@example.com/reset?token=synthetic-private#private'];
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), ['headers' => $headers]);
    self::assertSame('https://example.com/reset', $trace->snapshot()->request->headers->values['ReFeReR']);
    self::assertStringContainsString('synthetic-private', $headers['ReFeReR']);
  }

  /** @return void 双斜线和路径中的字面量分隔符不得被 URL 解析改写。 */
  public function testExtractPathPreservesRouterIdentity(): void
  {
    $request = $this->createMock(Request::class);
    $request->server = ['path_info' => '//private/login'];
    self::assertSame('//private/login', RequestCapture::extractPath($request));
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['//private/login']]);
    self::assertFalse($options->shouldCollect(RequestCapture::extractPath($request)));
    $request->server = ['path_info' => '/literal#fragment?path'];
    self::assertSame('/literal#fragment?path', RequestCapture::extractPath($request));
    $request->server = ['request_uri' => '//private/login?token=private'];
    self::assertSame('//private/login', RequestCapture::extractPath($request));
  }
}
