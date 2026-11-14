<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Viswoole\HttpServer\Response;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/** 验证响应头容器脱敏与跨次调用共享预算，防止监控影响业务发送。 */
final class ResponseHeaderBudgetTest extends TestCase
{
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  public function testContainerRedactionDoesNotBreakJsonResponse(): void
  {
    $raw = $this->createMock(SwooleResponse::class);
    $raw->method('header')->willReturn(true);
    $raw->method('status')->willReturn(true);
    $raw->method('isWritable')->willReturn(true);
    $raw->method('end')->willReturn(true);
    $trace = $this->createTrace(['sensitive_keys' => ['headers']]);
    TraceContext::attach($trace);
    self::assertTrue((new Response($raw))->json(['ok' => true])->send());
    $response = $trace->finish()['response'];
    self::assertSame('[REDACTED]', $response['headers']);
    self::assertSame(['ok' => true], $response['body']['content']);
    self::assertTrue($response['completed']);
  }

  public function testHeaderBytesShareBudgetAcrossSnapshotsAndUpdates(): void
  {
    $trace = $this->createTrace(['max_body_bytes' => 64]);
    $trace->updateResponse(['headers' => ['X-Initial' => str_repeat('x', 2000)]]);
    for ($index = 0; $index < 250; $index++) {
      $trace->updateResponseHeader('X-Test-' . $index, str_repeat('x', 1024));
    }
    $response = $trace->finish()['response'];
    self::assertLessThanOrEqual(4096, $this->countHeaderBytes($response['headers']));
    self::assertTrue($response['headers_truncated']);
  }

  public function testHeaderCountIsBoundedAndNamesAreCaseInsensitive(): void
  {
    $trace = $this->createTrace();
    for ($index = 0; $index < 250; $index++) $trace->updateResponseHeader('X-Test-' . $index, 'x');
    $response = $trace->finish()['response'];
    self::assertLessThanOrEqual(100, count(array_diff_key($response['headers'], ['__truncated' => true])));
    self::assertTrue($response['headers_truncated']);
    $trace = $this->createTrace();
    $trace->updateResponseHeader('X-Name', 'first');
    $trace->updateResponseHeader('x-name', 'second');
    self::assertSame(['x-name' => 'second'], $trace->finish()['response']['headers']);
  }

  public function testOversizedReplacementDropsStaleValueAndKeepsFieldRedaction(): void
  {
    $trace = $this->createTrace(['max_body_bytes' => 0]);
    $trace->updateResponseHeader('X-Name', 'old');
    $trace->updateResponseHeader('X-Name', str_repeat('x', 5000));
    $trace->updateResponseHeader('Set-Cookie', 'private');
    $response = $trace->finish()['response'];
    self::assertArrayNotHasKey('x-name', $response['headers']);
    self::assertSame('[REDACTED]', $response['headers']['set-cookie']);
    self::assertTrue($response['headers_truncated']);
  }

  public function testExactByteBudgetDoesNotGrowWhenSnapshotIsCreated(): void
  {
    $trace = $this->createTrace(['max_body_bytes' => 0]);
    $trace->updateResponseHeader('X', str_repeat('a', 4095));
    $response = $trace->finish()['response'];
    self::assertSame(4096, $this->countHeaderBytes($response['headers']));
    self::assertFalse($response['headers_truncated']);
  }

  public function testReplacingAHeaderReleasesItsPreviousBudget(): void
  {
    $trace = $this->createTrace(['max_body_bytes' => 0]);
    for ($index = 0; $index < 200; $index++) $trace->updateResponseHeader('X', str_repeat('a', 3000));
    $response = $trace->finish()['response'];
    self::assertCount(1, $response['headers']);
    self::assertSame(3001, $this->countHeaderBytes($response['headers']));
    self::assertFalse($response['headers_truncated']);
  }

  /** @param array $options 配置覆盖 @return RequestTrace 测试采集器 */
  private function createTrace(array $options = []): RequestTrace
  {
    return new RequestTrace(new MonitorOptions($options + ['threshold_ms' => 0]), []);
  }

  /** @param array $headers 采集的响应头 @return int 键值总字节数 */
  private function countHeaderBytes(array $headers): int
  {
    $bytes = 0;
    foreach ($headers as $key => $value) {
      if ($key !== '__truncated') $bytes += strlen($key) + strlen($value);
    }
    return $bytes;
  }
}
