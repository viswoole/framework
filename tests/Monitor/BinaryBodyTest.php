<?php

declare(strict_types=1);

/* 职责：验证二进制和非白名单内容不解析、不读取原文、不额外缓存。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Viswoole\Monitor\Dto\CaptureState;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;

/** 正文内容类型白名单的安全和内存边界。 */
final class BinaryBodyTest extends TestCase
{
  /**
   * 非白名单正文即使恰好是合法 JSON 也不解析
   *
   * @return void
   */
  public function testBinaryAndTextBodiesNeverUseJsonSniffing(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions());
    foreach (['application/octet-stream', 'image/png', 'video/mp4', 'application/zip', 'text/plain', 'text/event-stream', '', 'application/notjson'] as $type) {
      $body = $sanitizer->sanitizeBody('{"name":"synthetic"}', $type);
      self::assertSame('[UNSTRUCTURED_BODY]', $body['content']);
      self::assertSame('unsupported_content_type', $body['omitted_reason']);
      self::assertSame(20, $body['bytes']);
    }
  }

  /**
   * 二进制请求不调用 rawContent
   *
   * @return void
   */
  public function testBinaryRequestDoesNotReadRawContent(): void
  {
    $request = $this->createMock(Request::class);
    $request->header = ['content-type' => 'application/octet-stream', 'content-length' => '1000000'];
    $request->server = ['request_method' => 'POST', 'request_uri' => '/upload'];
    $request->expects(self::never())->method('rawContent');
    $record = (new RequestCapture(new MonitorOptions()))->capture($request);
    self::assertFalse($record['body']['size_known']);
    self::assertSame(0, $record['body']['bytes']);
    self::assertSame('application/octet-stream', $record['body']['content_type']);
  }

  /**
   * 分段二进制响应不缓存正文但累计实际发送字节
   *
   * @return void
   */
  public function testBinaryResponseAvoidsBufferAndKeepsByteCount(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    $trace->updateResponse(['content_type' => 'image/png']);
    $trace->appendResponse(str_repeat("\x00\xff", 5000));
    $trace->appendResponse('tail', true);
    $capture = (new \ReflectionProperty(RequestTrace::class, 'responseCapture'))->getValue($trace);
    self::assertSame('', (new \ReflectionProperty(\Viswoole\Monitor\ResponseCapture::class, 'responseBody'))->getValue($capture));
    $body = $trace->snapshot()->response->body;
    self::assertSame(10004, $body->bytes);
    self::assertTrue($body->isSizeKnown);
    self::assertSame(CaptureState::OMITTED, $body->state);
    self::assertNull($body->content);
    self::assertFalse($body->isTruncated);
  }

  /**
   * JSON 后缀及表单严格按媒体类型解析
   *
   * @return void
   */
  public function testStructuredMediaRemainSupported(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions());
    foreach (['application/json', 'Application/Problem+Json; charset=utf-8'] as $type) {
      self::assertSame(['ok' => true], $sanitizer->sanitizeBody('{"ok":true}', $type)['content']);
    }
    self::assertSame(['name' => 'value'], $sanitizer->sanitizeBody('name=value', 'application/x-www-form-urlencoded')['content']);
  }
}
