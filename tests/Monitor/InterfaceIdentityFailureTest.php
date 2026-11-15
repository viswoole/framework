<?php

declare(strict_types=1);

/* 职责：验证不可配置路由不会阻断其他规则，也不会被内部分发重新归属。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\Runtime\MemoryStateStore;
use Viswoole\Monitor\Runtime\ObservationFailure;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\Route\Route;
use Viswoole\Router\RouteIdentity;
use Viswoole\Tests\Monitor\Support\ExactRouteFixture;

/** 通过真实路由分发验证身份边界，不修改路由允许的请求范围。 */
final class InterfaceIdentityFailureTest extends TestCase
{
  private int $lastReportedAt;

  /** @return void 保留进程级限频状态，避免预期告警影响其他测试 */
  protected function setUp(): void
  {
    $this->lastReportedAt = (new ReflectionProperty(ObservationFailure::class, 'lastReportedAt'))->getValue();
  }

  /** @return void 恢复限频状态并清理请求，保证测试顺序不影响结果 */
  protected function tearDown(): void
  {
    (new ReflectionProperty(ObservationFailure::class, 'lastReportedAt'))->setValue(null, $this->lastReportedAt);
    TraceContext::clear();
  }

  /** @return iterable<string,array{string,string}> 路由有效但超出监控身份边界的定义 */
  public static function invalidIdentities(): iterable
  {
    yield 'extended method' => ['/discovery', 'M-SEARCH'];
    yield 'long template' => ['/' . str_repeat('x', 2048), 'GET'];
    yield 'encoded identity limit' => ['/' . str_repeat('x', 2000), 'GET'];
  }

  /**
   * 不可配置候选不阻断目录和其他接口的规则新增、替换
   * @param string $path 外层路径
   * @param string $method 外层方法
   * @return void
   */
  #[DataProvider('invalidIdentities')]
  public function testUnconfigurableRouteDoesNotBlockOtherOverrides(string $path, string $method): void
  {
    $fixture = new ExactRouteFixture([
      new Route('/inner', static fn (): string => 'ok', methods: ['GET']),
      new Route($path, static fn (): string => 'outer', methods: [$method]),
    ]);
    $runtime = $this->createRuntime($fixture);
    $interfaces = $fixture->router->getInterfaces();
    self::assertCount(1, $interfaces);
    self::assertSame('/inner', $interfaces[0]->template);
    $first = $runtime->setOverride('inner', $interfaces[0], 100);
    $replacement = $runtime->setOverride('inner', $interfaces[0], 0);
    self::assertGreaterThan($first->version, $replacement->version);
    self::assertSame(0.0, $replacement->thresholdMs);
    self::assertSame('outer', $fixture->router->dispatch($path, $method, 'localhost'));
  }

  /**
   * 外层身份失败后仍按入口门槛保存，不能采用内部接口覆盖
   * @param string $path 外层路径
   * @param string $method 外层方法
   * @return void
   */
  #[DataProvider('invalidIdentities')]
  public function testIdentityFailureFreezesGlobalPolicyBeforeNestedDispatch(string $path, string $method): void
  {
    $fixture = null;
    $outer = new Route($path, static function () use (&$fixture): string {
      return $fixture->router->dispatch('/inner', 'GET', 'localhost');
    }, methods: [$method]);
    $fixture = new ExactRouteFixture([$outer, new Route('/inner', static fn (): string => 'ok', methods: ['GET'])]);
    $runtime = $this->createRuntime($fixture);
    $runtime->setOverride('inner', new RouteIdentity('GET', '/inner', ['*']), 0);
    $trace = new RequestTrace(new MonitorOptions(), []);
    $trace->attachRuntime($runtime);
    TraceContext::attach($trace);
    // 预期的观测告警不应中断业务；只接管此错误类别，其他错误仍交给测试框架。
    set_error_handler(static fn (int $level, string $message): bool => $level === E_USER_WARNING
      && str_starts_with($message, '动态采集策略读取失败: '));
    try { self::assertSame('ok', $fixture->router->dispatch($path, $method, 'localhost')); }
    finally { restore_error_handler(); }
    self::assertNull($trace->snapshot($trace->startedAt + 999_000_000));
    $snapshot = $trace->snapshot($trace->startedAt + 1_000_000_000);
    self::assertNotNull($snapshot);
    self::assertNull($snapshot->capture->routeIdentity);
    self::assertSame([], $snapshot->capture->matchedOverrides);
    self::assertSame(1000.0, $snapshot->capture->effectiveThresholdMs);
  }

  /**
   * 使用隔离内存状态，专注路由与策略边界
   * @param ExactRouteFixture $fixture 真实路由装配
   * @return MonitorRuntime 管理入口
   */
  private function createRuntime(ExactRouteFixture $fixture): MonitorRuntime
  {
    return new MonitorRuntime(new MonitorOptions(), $fixture->catalog, new MemoryStateStore(RuntimeState::initial(1000)));
  }
}
