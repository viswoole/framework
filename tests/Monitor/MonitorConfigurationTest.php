<?php

declare(strict_types=1);

/* 职责：验证显式存储选择、配置校验及路由采集，不依赖 HTTP 服务。 */

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Monitor\Contract\TraceWriterInterface;
use Viswoole\Monitor\Http\RouteCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorService;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 配置与路由契约
 *
 * 新模块启用时不能隐式创建存储副作用，路由参数须独立脱敏。
 */
final class MonitorConfigurationTest extends TestCase
{
  /**
   * 验证缺少存储配置时明确失败
   *
   * @return void
   */
  public function testEnabledMonitorRequiresExplicitWriter(): void
  {
    $app = App::factory();
    $original = $app->make(MonitorOptions::class);
    (new MonitorService($app))->register();
    $app->bind(MonitorOptions::class, new MonitorOptions(['enabled' => true]));
    try {
      $this->expectException(InvalidArgumentException::class);
      $app->make(TraceWriterInterface::class);
    } finally {
      $app->remove(TraceWriterInterface::class);
      $app->bind(MonitorOptions::class, $original);
    }
  }

  /**
   * 验证自定义存储可通过配置解析
   *
   * @return void
   */
  public function testConfiguredWriterIsResolvedByContainer(): void
  {
    $app = App::factory();
    $original = $app->make(MonitorOptions::class);
    (new MonitorService($app))->register();
    $app->bind(MonitorOptions::class, new MonitorOptions(['writer' => InMemoryTraceWriter::class]));
    try {
      self::assertInstanceOf(InMemoryTraceWriter::class, $app->make(TraceWriterInterface::class));
    } finally {
      $app->remove(TraceWriterInterface::class);
      $app->remove(InMemoryTraceWriter::class);
      $app->bind(MonitorOptions::class, $original);
    }
  }

  /**
   * 记录模板和独立路径参数
   *
   * @return void
   */
  public function testRouteParametersAreCapturedAndRedacted(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), ['query' => ['page' => 1]]);
    TraceContext::attach($trace);
    try {
      RouteCapture::record(null, ['/users/{ticket}'], ['ticket' => 'synthetic'], static fn (): string => 'ok');
      $request = $trace->finish()['request'];
      self::assertSame(['/users/{ticket}'], $request['route']['paths']);
      self::assertSame('[REDACTED]', $request['route']['parameters']['ticket']);
      self::assertSame(['page' => 1], $request['query']);
    } finally {
      TraceContext::clear();
    }
  }
}
