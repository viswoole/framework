<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;

/**
 * 请求采集状态测试
 *
 * 验证阈值边界、脱敏和有界采集，不依赖真实等待。
 */
final class RequestTraceTest extends TestCase
{
  /**
   * 阈值边界：差一纳秒不记录，恰好达到阈值才记录；请求头与查询参数按字段脱敏
   */
  public function testThresholdBoundaryAndRequestRedaction(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 100]), [
      'headers' => ['Authorization' => 'Bearer private'],
      'query' => ['password' => 'secret', 'page' => 1],
    ]);
    self::assertNull($trace->finish($trace->startedAt + 99_999_999));
    $record = $trace->finish($trace->startedAt + 100_000_000);
    self::assertSame(100.0, $record['duration_ms']);
    self::assertSame('[REDACTED]', $record['request']['headers']['Authorization']);
    self::assertSame('[REDACTED]', $record['request']['query']['password']);
    self::assertSame(1, $record['request']['query']['page']);
  }

  /**
   * 分段发送的 JSON 正文在拼接完成后整体解析并脱敏
   */
  public function testChunkedJsonIsRedactedAfterAssembly(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    $trace->updateResponse(['content_type' => 'application/json', 'status' => 201]);
    $trace->appendResponse('{"password":"');
    $trace->appendResponse('secret","ok":true}', true);
    $response = $trace->finish()['response'];
    self::assertSame(['password' => '[REDACTED]', 'ok' => true], $response['body']['content']);
    self::assertSame(201, $response['status']);
    self::assertTrue($response['completed']);
    self::assertGreaterThanOrEqual(0, $trace->finish()['response_time_ms']);
  }

  /**
   * 超限正文完全不输出，仅保留实际字节数与截断标记，防止半截 JSON 绕过脱敏
   */
  public function testOversizedResponseOmitsPartialJson(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'max_body_bytes' => 10]), []);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->appendResponse('{"password');
    $trace->appendResponse('\":\"private\"}', true);
    $body = $trace->finish()['response']['body'];
    self::assertTrue($body['truncated']);
    self::assertSame('[BODY_LIMIT]', $body['content']);
    self::assertGreaterThan(10, $body['bytes']);
  }

  /**
   * 操作条数超限后不再分配位置，已记录的操作保持开始顺序且可正常结束
   */
  public function testOperationBudgetPreservesBeginOrder(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'max_operations' => 2]), []);
    self::assertSame(0, $trace->beginOperation(['operation' => 'first']));
    self::assertSame(1, $trace->beginOperation(['operation' => 'second']));
    self::assertNull($trace->beginOperation(['operation' => 'third']));
    $trace->endOperation(1, hrtime(true), ['success' => true]);
    $trace->endOperation(0, hrtime(true), ['success' => false]);
    $record = $trace->finish();
    self::assertSame(['first', 'second'], array_column($record['operations'], 'operation'));
    self::assertSame(1, $record['dropped_operations']);
    self::assertFalse($record['operations'][0]['success']);
  }

  /**
   * URL 编码表单按字段脱敏（含自定义敏感字段）；解析失败的 JSON 正文整体占位
   */
  public function testFormBodyAndCustomSensitiveFields(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions(['privacy' => PrivacyOptions::redact(['identity_number'])]));
    $body = $sanitizer->sanitizeBody('password=secret&identity_number=123&name=Jane', 'application/x-www-form-urlencoded');
    self::assertSame(['password' => '[REDACTED]', 'identity_number' => '[REDACTED]', 'name' => 'Jane'], $body['content']);
    self::assertSame('[UNPARSEABLE_BODY]', $sanitizer->sanitizeBody('{"password":"secret"', 'application/json')['content']);
  }

  /**
   * 非法配置（负数上限）在构造时即拒绝
   */
  public function testInvalidLimitsAreRejected(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    new MonitorOptions(['max_operations' => -1]);
  }
}
