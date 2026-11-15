<?php

declare(strict_types=1);

/* 职责：验证跨组件共享预算、UTF-8 编码和请求定稿边界。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/**
 * 快照预算与定稿边界
 *
 * 同时压满多个采集来源，防止局部上限通过而最终快照仍超限。
 */
final class SnapshotBudgetTest extends TestCase
{
  /**
   * 混合数据不能突破总预算
   *
   * 使用会被 JSON 转义的正文和长请求参数，验证预算按真实编码成本计算。
   *
   * @return void
   */
  public function testMixedPayloadsStayWithinTotalBudget(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'max_snapshot_bytes' => 16384, 'max_body_bytes' => 65536]);
    $trace = new RequestTrace($options, ['query' => ['name' => str_repeat('汉', 5000)]]);
    TraceContext::attach($trace);
    try {
      for ($index = 0; $index < 100; $index++) {
        $trace->updateResponseHeader('X-Test-' . $index, str_repeat('a', 1000));
        TraceContext::measure(['type' => 'database', 'sql' => str_repeat('x', 2000)], static fn (): bool => true);
      }
      $trace->updateResponse(['content_type' => 'application/json']);
      $trace->appendResponse(json_encode(['name' => str_repeat('汉', 2000)], JSON_UNESCAPED_UNICODE), true);
      $record = $trace->finish();
      $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      self::assertLessThanOrEqual(16384, strlen($encoded));
      self::assertTrue($record['snapshot_truncated']);
      self::assertSame(100, $record['operation_summary']['database']['count']);
    } finally {
      TraceContext::clear();
    }
  }

  /**
   * 字节截断不会留下非法 UTF-8
   *
   * 使用方可以直接使用严格 JSON 编码，不需要为了采集器额外容错。
   *
   * @return void
   */
  public function testTruncatedMultibyteStringsRemainEncodable(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions(['max_body_bytes' => 4096]));
    $safe = $sanitizer->sanitize(['name' => str_repeat('汉', 5000)]);
    self::assertIsString(json_encode($safe, JSON_THROW_ON_ERROR));
    self::assertSame('[NON_FINITE]', $sanitizer->sanitize(INF));
  }

  /**
   * 定稿后忽略子协程迟到事件
   *
   * 请求状态可被显式传递，但交付之后不能继续增长操作或响应数据。
   *
   * @return void
   */
  public function testLateEventsCannotMutateFinalizedTrace(): void
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]), $writer);
    $monitor->start('/closed', static fn (): array => []);
    $trace = TraceContext::current();
    $monitor->finish();
    $before = $trace->finish(123 + $trace->startedAt);
    $trace->beginOperation(['type' => 'database']);
    $trace->appendResponse('synthetic');
    $trace->updateResponseHeader('X-Late', 'synthetic');
    self::assertSame($before, $trace->finish(123 + $trace->startedAt));
    self::assertNull(TraceContext::current());
  }
}
