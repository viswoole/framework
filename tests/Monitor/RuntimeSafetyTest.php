<?php

declare(strict_types=1);

/* 职责：验证全局回退、隐私开关及规则分散后的预算独立性。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\Runtime\StateStore;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\RouteIdentity;

/** 保证监控扩展不会扩大业务采集授权或影响业务结果。 */
final class RuntimeSafetyTest extends TestCase
{
  /** @return void 清理请求上下文 */
  protected function tearDown(): void { TraceContext::clear(); }

  /** @return void 动态观测失败回退已固定策略，重复失败限频 */
  public function testReadFailureKeepsEntryPolicyAndRateLimitsWarnings(): void
  {
    $store = new class implements StateStore {
      public bool $shouldFail = false;
      public function read(): RuntimeState
      {
        if ($this->shouldFail) { throw new RuntimeException('private database password'); }
        return new RuntimeState(['threshold_ms' => 200.0, 'runtime_id' => 'test', 'version' => 1]);
      }
      public function update(callable $change): RuntimeState { return $change($this->read()); }
    };
    $runtime = new MonitorRuntime(new MonitorOptions(), new InterfaceCatalog(), $store);
    $trace = new RequestTrace(new MonitorOptions(), []);
    $trace->attachRuntime($runtime);
    $store->shouldFail = true;
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
      $warnings[] = $message;
      return true;
    });
    try {
      $trace->freezeCapturePolicy(new RouteIdentity('GET', '/orders/{id}', ['*']));
      $second = new RequestTrace(new MonitorOptions(['threshold_ms' => 300]), []);
      $second->attachRuntime($runtime);
    } finally { restore_error_handler(); }
    self::assertCount(1, $warnings);
    self::assertStringNotContainsString('password', $warnings[0]);
    $snapshot = $trace->snapshot($trace->startedAt + 200_000_000);
    self::assertSame(200.0, $snapshot->capture->globalThresholdMs);
    self::assertNull($snapshot->capture->routeIdentity);
    self::assertNull($second->snapshot($second->startedAt + 299_000_000));
  }

  /** @return void 禁用及排除优先，不能通过覆盖规则打开采集 */
  public function testDisabledAndExcludedPathsRemainExcluded(): void
  {
    foreach ([['enabled' => false], ['enabled' => true, 'exclude_paths' => ['/orders/*']]] as $config) {
      $options = new MonitorOptions($config);
      $runtime = new MonitorRuntime($options, new InterfaceCatalog());
      $writer = new InMemoryTraceWriter();
      $monitor = new SlowRequestMonitor($options, $writer, $runtime);
      $monitor->start('/orders/1', static function (): array { throw new RuntimeException('不能读取请求'); });
      self::assertNull(TraceContext::current());
      $monitor->finish();
      self::assertSame([], $writer->snapshots);
    }
  }

  /** @return void 不同接口规则不按全部同时命中计算，默认容量可容纳 256 个分散接口 */
  public function testDispersedRulesFitMinimumSnapshotBudget(): void
  {
    $catalog = new InterfaceCatalog();
    $routes = [];
    for ($index = 0; $index < 256; $index++) {
      $route = new RouteIdentity('GET', '/orders' . $index . '/{id}', ['*']);
      $catalog->register((string)$index, ['template' => $route->template, 'domains' => ['*'], 'methods' => ['GET']]);
      $routes[] = $route;
    }
    $catalog->complete();
    $runtime = new MonitorRuntime(new MonitorOptions(['max_snapshot_bytes' => 16384]), $catalog);
    foreach ($routes as $index => $route) { $runtime->setOverride('r' . $index, $route, 0); }
    self::assertCount(256, $runtime->getOverrides());
    self::assertCount(1, $runtime->resolve($routes[100])->rules);
  }
  /** @return void 两个协程使用不同接口，来源及固定配置版本不串用 */
  public function testConcurrentInterfacePoliciesRemainIsolated(): void
  {
    $catalog = new InterfaceCatalog();
    $routes = [new RouteIdentity('GET', '/first', ['*']), new RouteIdentity('GET', '/second', ['*'])];
    foreach ($routes as $index => $route) {
      $catalog->register((string)$index, ['template' => $route->template, 'domains' => ['*'], 'methods' => ['GET']]);
    }
    $catalog->complete();
    $options = new MonitorOptions(['enabled' => true]);
    $runtime = new MonitorRuntime($options, $catalog);
    foreach ($routes as $index => $route) { $runtime->setOverride('r' . $index, $route, 0); }
    $records = [];
    \Swoole\Coroutine\run(static function () use ($runtime, $routes, $options, &$records): void {
      $ready = new \Swoole\Coroutine\Channel(2);
      $release = new \Swoole\Coroutine\Channel(2);
      $done = new \Swoole\Coroutine\Channel(2);
      foreach ($routes as $index => $route) {
        \Swoole\Coroutine::create(static function () use ($runtime, $route, $options, $index, $ready, $release, $done, &$records): void {
          $trace = new RequestTrace($options, []);
          TraceContext::attach($trace);
          $trace->attachRuntime($runtime);
          $trace->freezeCapturePolicy($route);
          $ready->push(true);
          $release->pop();
          $records[$index] = TraceContext::current()->snapshot();
          TraceContext::clear();
          $done->push(true);
        });
      }
      $ready->pop(); $ready->pop();
      $runtime->removeOverride('r0');
      $release->push(true); $release->push(true);
      $done->pop(); $done->pop();
    });
    foreach ($records as $index => $snapshot) {
      self::assertSame('r' . $index, $snapshot->capture->matchedOverrides[0]->id);
      self::assertSame($routes[$index]->template, $snapshot->capture->routeIdentity->template);
      self::assertSame(2, $snapshot->capture->configVersion);
    }
  }
}
