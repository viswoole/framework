<?php

declare(strict_types=1);

/* 职责：验证独立采集开关可减少采集内容，且不改变原操作执行结果。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 独立采集开关回归
 *
 * 关闭内容采集后仍保留请求耗时及响应状态，数据库和缓存仍照常执行。
 */
final class CaptureSwitchTest extends TestCase
{
  /**
   * 关闭请求参数采集不读取正文
   *
   * @return void
   */
  public function testRequestSwitchAvoidsReadingBody(): void
  {
    $options = new MonitorOptions(['capture_request' => false]);
    $request = $this->createMock(Request::class);
    $request->server = ['request_method' => 'POST', 'path_info' => '/private'];
    $request->expects(self::never())->method('rawContent');
    self::assertSame(['method' => 'POST', 'path' => '/private'], (new RequestCapture($options))->capture($request));
  }

  /**
   * 独立开关仅关闭观测内容
   *
   * @return void
   */
  public function testSwitchesPreserveBusinessResultsAndResponseStatus(): void
  {
    $trace = new RequestTrace(new MonitorOptions([
      'threshold_ms' => 0, 'capture_response' => false, 'capture_database' => false, 'capture_cache' => false,
    ]), []);
    TraceContext::attach($trace);
    try {
      self::assertSame(42, TraceContext::measure(['type' => 'database'], static fn (): int => 42));
      self::assertSame(true, TraceContext::measure(['type' => 'cache'], static fn (): bool => true));
      $trace->updateResponse(['status' => 201, 'headers' => ['X-Test' => 'synthetic']]);
      $trace->appendResponse('{"name":"synthetic"}', true);
      $record = $trace->finish();
      self::assertSame([], $record['operations']);
      self::assertSame([], $record['operation_summary']);
      self::assertSame([], $record['response']['headers']);
      self::assertSame(201, $record['response']['status']);
      self::assertSame('[CAPTURE_DISABLED]', $record['response']['body']['content']);
      self::assertTrue($record['response']['completed']);
    } finally {
      TraceContext::clear();
    }
  }
}
