<?php

declare(strict_types=1);

/* 职责：验证失败路由和预检在处理器执行前归还来源预算，并固定入口全局标准。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\Runtime\MemoryStateStore;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\Route;
use Viswoole\Tests\Monitor\Support\ExactRouteFixture;

/** 使用生产分发链验证 404、后缀失败、OPTIONS 和嵌套失败语义。 */
final class UnmatchedPolicyRegressionTest extends TestCase
{
  /** @return void 清理请求上下文 */
  protected function tearDown(): void { TraceContext::clear(); }

  /** @return void miss 处理器前应回收预算，且不采用路由期间更新的新全局阈值 */
  public function testMissAndSuffixFailureFreezeEntryPolicyBeforeHandler(): void
  {
    foreach (['/missing', '/orders/123.xml'] as $path) {
      $route = new Route('/orders/{id}', static fn (string $id): string => $id, methods: ['GET']);
      $route->setSuffix('html');
      $fixture = new ExactRouteFixture([$route]);
      $options = new MonitorOptions(['threshold_ms' => 0]);
      $runtime = new MonitorRuntime($options, $fixture->catalog, new MemoryStateStore(RuntimeState::initial(0)));
      $runtime->setOverride('orders', $fixture->catalog->getInterfaces()[0], 0);
      $trace = new RequestTrace($options, ['path' => $path]);
      $trace->attachRuntime($runtime);
      TraceContext::attach($trace);
      $runtime->setThresholdMs(1e9);
      $fixture->router->miss(static fn (): int => self::recordOperations(), 'GET');
      self::assertSame(55, $fixture->router->dispatch($path, 'GET', 'localhost'));
      $snapshot = $trace->snapshot();
      self::assertNotNull($snapshot);
      self::assertSame(0.0, $snapshot->capture->globalThresholdMs);
      self::assertNull($snapshot->capture->routeIdentity);
      self::assertSame([], $snapshot->capture->matchedOverrides);
      self::assertFalse($snapshot->snapshotTruncated);
      TraceContext::clear();
    }
  }

  /** @return void 没有 miss 处理器时仍需冻结失败策略，后续内部成功分发不能更换身份 */
  public function testFailureWithoutMissCannotBeReclassifiedByNestedDispatch(): void
  {
    $fixture = new ExactRouteFixture([new Route('/orders/{id}', static fn (string $id): string => $id, methods: ['GET'])]);
    $options = new MonitorOptions(['threshold_ms' => 0]);
    $runtime = new MonitorRuntime($options, $fixture->catalog);
    $runtime->setOverride('orders', $fixture->catalog->getInterfaces()[0], 0);
    $trace = new RequestTrace($options, []);
    $trace->attachRuntime($runtime);
    TraceContext::attach($trace);
    try { $fixture->router->dispatch('/missing', 'GET', 'localhost'); }
    catch (RouteNotFoundException $error) { self::assertNotSame('', $error->getMessage()); }
    $fixture->router->dispatch('/orders/123', 'GET', 'localhost');
    self::assertNull($trace->snapshot()->capture->routeIdentity);
    self::assertSame([], $trace->snapshot()->capture->matchedOverrides);
  }

  /** @return void OPTIONS 仍采用入口全局策略，提前回收而不命中 GET 覆盖 */
  public function testOptionsFreezesGlobalPolicyBeforeHandler(): void
  {
    $fixture = new ExactRouteFixture([new Route('/orders/{id}', static fn (): int => self::recordOperations(), methods: ['GET'])]);
    $options = new MonitorOptions(['threshold_ms' => 0]);
    $runtime = new MonitorRuntime($options, $fixture->catalog);
    $runtime->setOverride('orders', $fixture->catalog->getInterfaces()[0], 0);
    $trace = new RequestTrace($options, []);
    $trace->attachRuntime($runtime);
    TraceContext::attach($trace);
    self::assertSame(55, $fixture->router->dispatch('/orders/123', 'OPTIONS', 'localhost'));
    self::assertNull($trace->snapshot()->capture->routeIdentity);
    self::assertSame([], $trace->snapshot()->capture->matchedOverrides);
  }

  /** @return void 已固定的外层接口不受内部路由失败影响 */
  public function testNestedFailureDoesNotReplaceSuccessfulOuterPolicy(): void
  {
    $fixture = null;
    $outer = new Route('/outer', static function () use (&$fixture): string {
      try { $fixture->router->dispatch('/missing', 'GET', 'localhost'); }
      catch (RouteNotFoundException $error) { return $error->getMessage(); }
      return 'unexpected';
    }, methods: ['GET']);
    $fixture = new ExactRouteFixture([$outer]);
    $options = new MonitorOptions(['threshold_ms' => 0]);
    $runtime = new MonitorRuntime($options, $fixture->catalog);
    $runtime->setOverride('outer', $fixture->catalog->getInterfaces()[0], 0);
    $trace = new RequestTrace($options, []);
    $trace->attachRuntime($runtime);
    TraceContext::attach($trace);
    $fixture->router->dispatch('/outer', 'GET', 'localhost');
    self::assertSame('/outer', $trace->snapshot()->capture->routeIdentity->template);
    self::assertSame('outer', $trace->snapshot()->capture->matchedOverrides[0]->id);
  }

  /** @return void 方法或域名校验失败后，异常处理阶段仍可使用已归还的明细预算 */
  public function testMethodAndDomainFailuresReturnReservation(): void
  {
    foreach ([['POST', 'allowed.example'], ['GET', 'wrong.example']] as [$method, $domain]) {
      $route = new Route('/private/{id}', static fn (string $id): string => $id, methods: ['GET']);
      $route->setDomain('allowed.example');
      $fixture = new ExactRouteFixture([$route]);
      $options = new MonitorOptions(['threshold_ms' => 0]);
      $trace = new RequestTrace($options, []);
      TraceContext::attach($trace);
      try { $fixture->router->dispatch('/private/1', $method, $domain); }
      catch (RouteNotFoundException $error) { self::assertNotSame('', $error->getMessage()); }
      self::assertSame(55, self::recordOperations());
      self::assertNull($trace->snapshot()->capture->routeIdentity);
      TraceContext::clear();
    }
  }

  /** @return int 真实可保留的约 220KiB 操作数 */
  private static function recordOperations(): int
  {
    for ($index = 0; $index < 55; $index++) {
      TraceContext::measure(['type' => 'other', 'payload' => str_repeat('x', 4000)], static fn (): bool => true);
    }
    return count(TraceContext::current()->finish()['operations']);
  }
}
