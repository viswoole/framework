<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Viswoole\HttpServer\Response;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 响应监控测试
 *
 * 验证响应发送边界，确保最终发送正文与分段内容均可观测。
 */
final class ResponseMonitoringTest extends TestCase
{
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /**
   * JSON 正文脱敏、状态码与敏感响应头（如 set-cookie）入记录并标记完成
   */
  public function testJsonStatusAndSensitiveHeadersAreCaptured(): void
  {
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('header')->willReturn(true);
    $raw->method('status')->willReturn(true);
    $raw->method('isWritable')->willReturn(true);
    $raw->method('end')->willReturn(true);
    $raw->header = ['set-cookie' => 'session=private'];
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $response = new Response($raw);
    self::assertTrue($response->status(201)->json(['password' => 'secret', 'id' => 1])->send());
    $record = $trace->finish()['response'];
    self::assertSame(201, $record['status']);
    self::assertSame('[REDACTED]', $record['headers']['set-cookie']);
    self::assertSame(['password' => '[REDACTED]', 'id' => 1], $record['body']['content']);
    self::assertTrue($record['completed']);
  }

  /**
   * 分段写入与重定向成功后分别记录正文与目标，并标记响应完成
   */
  public function testSuccessfulWritesAndRedirectAreRecorded(): void
  {
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('header')->willReturn(true);
    $raw->method('write')->willReturn(true);
    $raw->method('redirect')->willReturn(true);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $response = new Response($raw);
    $response->write('hello');
    self::assertTrue($response->redirect('/login', 307));
    $record = $trace->finish()['response'];
    self::assertSame('[UNSTRUCTURED_BODY]', $record['body']['content']);
    self::assertSame(5, $record['body']['bytes']);
    self::assertSame(307, $record['status']);
    self::assertTrue($record['completed']);
  }

  /**
   * 重定向地址剥离 query 与锚点后入记录，URL 内嵌敏感参数不落日志
   */
  public function testRedirectUriIsRecordedWithoutQuery(): void
  {
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('redirect')->willReturn(true);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $response = new Response($raw);
    self::assertTrue($response->redirect('/oauth/callback?token=private-token&code=1#section', 302));
    self::assertTrue($response->redirect('https://example.com:8443/secure?access_token=private-token', 301));
    $record = $trace->finish()['response'];
    self::assertSame('https://example.com:8443/secure', $record['redirect']);
    self::assertStringNotContainsString('private-token', json_encode($record, JSON_THROW_ON_ERROR));
  }

  /**
   * 发送失败不标记响应完成、不记录正文，响应耗时保持 null
   */
  public function testFailedSendDoesNotClaimCompletedResponse(): void
  {
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('header')->willReturn(true);
    $raw->method('isWritable')->willReturn(true);
    $raw->method('end')->willReturn(false);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    self::assertFalse((new Response($raw))->send('failed'));
    $record = $trace->finish();
    self::assertFalse($record['response']['completed']);
    self::assertSame('[UNSTRUCTURED_BODY]', $record['response']['body']['content']);
    self::assertSame(0, $record['response']['body']['bytes']);
    self::assertNull($record['response_time_ms']);
  }
}
