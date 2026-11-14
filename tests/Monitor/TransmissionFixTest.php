<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Viswoole\HttpServer\Response;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/** 职责：验证实际响应代理的 SSE 安全边界与文件大小语义。 */
final class TransmissionFixTest extends TestCase
{
  /** @return void 清理请求状态，避免替身污染后续用例。 */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /** @return void 所有 SSE 换行都必须被转换为独立 data 行。 */
  public function testSseDataNormalizesAllLineEndings(): void
  {
    $raw = $this->createResponse();
    $raw->expects(self::once())->method('write')->with("id: 42\nevent: notice\ndata: a\ndata: b\ndata: c\ndata: \n\n")->willReturn(true);
    (new Response($raw))->sendEvent("a\r\nb\rc\n", 'notice', '42');
  }

  /** @return void 非法元数据必须在任何写入之前被拒绝。 */
  public function testSseRejectsInjectedFieldsBeforeWriting(): void
  {
    foreach ([["notice\ndata: forged", null], ["notice\rforged", null], [null, "42\r\n\n"], [null, "42\0"]] as [$event, $id]) {
      $raw = $this->createResponse();
      $raw->expects(self::never())->method('write');
      try {
        (new Response($raw))->sendEvent('safe', $event, $id);
        self::fail('非法 SSE 字段必须被拒绝');
      } catch (InvalidArgumentException $error) {
        self::assertStringContainsString('SSE', $error->getMessage());
      }
    }
  }

  /** @return void sendfile 返回成功不能证明已实际发送零字节或指定长度。 */
  public function testFileResponseHasUnknownActualSize(): void
  {
    foreach ([true, false] as $canCapture) {
      $raw = $this->createResponse();
      $raw->expects(self::once())->method('sendfile')->with(__FILE__, 0, 10)->willReturn(true);
      $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'capture_response' => $canCapture]), []);
      TraceContext::attach($trace);
      self::assertTrue((new Response($raw))->sendfile(__FILE__, 0, 10, 'application/octet-stream'));
      $snapshot = $trace->snapshot();
      self::assertTrue($snapshot->response->completed);
      self::assertFalse($snapshot->response->body->isSizeKnown);
      self::assertNull($snapshot->response->body->content);
      TraceContext::clear();
    }
  }

  /** @return SwooleResponse 原生响应替身；发送方法由各行为测试独立断言。 */
  private function createResponse(): SwooleResponse
  {
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('header')->willReturn(true);
    $raw->method('status')->willReturn(true);
    return $raw;
  }
}
