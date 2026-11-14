<?php

declare(strict_types=1);

/* 职责：验证请求 ID 标头开关、隔离与异常旁路，不依赖真实网络。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Response;
use Viswoole\Monitor\Http\RequestIdHeader;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/** 请求关联标头的开关边界。 */
final class RequestIdHeaderTest extends TestCase
{
  /**
   * 没有采集上下文时不操作标头
   *
   * @return void
   */
  public function testMissingTraceDoesNotWriteHeader(): void
  {
    TraceContext::clear();
    $response = $this->createMock(Response::class);
    $response->expects(self::never())->method('header');
    RequestIdHeader::apply($response);
  }

  /**
   * null 和空串均关闭输出
   *
   * @return void
   */
  public function testEmptyHeaderConfigurationDisablesOutput(): void
  {
    foreach ([null, ''] as $header) {
      TraceContext::attach(new RequestTrace(new MonitorOptions(['request_id' => ['response_header' => $header]]), []));
      $response = $this->createMock(Response::class);
      $response->expects(self::never())->method('header');
      try {
        RequestIdHeader::apply($response);
      } finally {
        TraceContext::clear();
      }
    }
  }
}
