<?php

declare(strict_types=1);

/* 职责：证明覆盖跟随真实接口归属，而非路径前缀或参数值。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\Runtime\MemoryStateStore;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\Route;
use Viswoole\Tests\Monitor\Support\ExactRouteFixture;

/** 验证真实路由选择、别名、方法、域名和伪静态行为。 */
final class ExactInterfaceTest extends TestCase
{
  /** @return void 测试结束清理协程上下文 */
  protected function tearDown(): void { TraceContext::clear(); }

  /** @return void 订单 ID 变化命中同一接口，但搜索、明细、别名和 POST 不命中 */
  public function testActualSelectedTemplateDefinesTarget(): void
  {
    $fixture = $this->fixture();
    $runtime = $this->runtime($fixture);
    $target = array_values(array_filter(
      $fixture->router->getInterfaces(),
      static fn ($route): bool => $route->method === 'GET' && $route->template === '/orders/{id}'
    ))[0];
    $runtime->setOverride('diagnose', $target, 0);
    foreach (['/orders/123', '/orders/456', '/ORDERS/789', '/orders/%31%32%33'] as $path) {
      $record = $this->dispatch($fixture, $runtime, ['path' => $path]);
      self::assertSame(['diagnose'], array_column($record->capture->toArray()['matched_overrides'], 'id'));
      self::assertSame($target->toArray(), $record->capture->routeIdentity->toArray());
    }
    foreach ([['path' => '/orders/search'], ['path' => '/orders/123/items'],
      ['path' => '/alias/123'], ['path' => '/orders/123', 'method' => 'POST']] as $request) {
      self::assertNull($this->dispatch($fixture, $runtime, $request));
    }
  }

  /** @return void 后缀归属注册模板，域名约束不等于本次 Host */
  public function testSuffixAndDomainIdentity(): void
  {
    $route = new Route('/file/{id}', static fn (string $id): string => $id, methods: ['GET']);
    $route->setSuffix('html');
    $route->setDomain('b.example.com', 'a.example.com');
    $fixture = new ExactRouteFixture([$route]);
    $runtime = $this->runtime($fixture);
    $target = $fixture->router->getInterfaces()[0];
    $runtime->setOverride('file', $target, 0);
    $snapshot = $this->dispatch($fixture, $runtime, ['path' => '/file/12.html', 'domain' => 'b.example.com']);
    self::assertSame('/file/{id}', $snapshot->capture->routeIdentity->template);
    self::assertSame(['a.example.com', 'b.example.com'], $snapshot->capture->routeIdentity->domainConstraints);
  }

  /** @return void 未匹配路由、域名拒绝和 OPTIONS 不进入接口覆盖 */
  public function testNoOverrideForMissDomainFailureOrOptions(): void
  {
    $fixture = $this->fixture();
    $runtime = $this->runtime($fixture);
    foreach ($fixture->router->getInterfaces() as $index => $route) { $runtime->setOverride('r' . $index, $route, 0); }
    self::assertNull($this->dispatch($fixture, $runtime, ['path' => '/missing']));
    self::assertNull($this->dispatch($fixture, $runtime, ['path' => '/orders/1', 'method' => 'OPTIONS']));
  }

  /** @return void 内部分发不能覆盖外层可信接口 */
  public function testNestedDispatchDoesNotReplaceOuterIdentity(): void
  {
    $fixture = null;
    $outer = new Route('/outer', static function () use (&$fixture): string {
      return $fixture->router->dispatch('/inner', 'GET', 'localhost');
    }, methods: ['GET']);
    $fixture = new ExactRouteFixture([$outer, new Route('/inner', static fn (): string => 'ok', methods: ['GET'])]);
    $runtime = $this->runtime($fixture);
    foreach ($fixture->router->getInterfaces() as $route) { $runtime->setOverride($route->template === '/outer' ? 'outer' : 'inner', $route, 0); }
    $snapshot = $this->dispatch($fixture, $runtime, ['path' => '/outer']);
    self::assertSame('/outer', $snapshot->capture->routeIdentity->template);
    self::assertSame('outer', $snapshot->capture->matchedOverrides[0]->id);
  }

  /** @return void 不限方法的注册项展开为具体接口，不能产生 * 或预检覆盖目标 */
  public function testAnyMethodDirectoryExpandsConcreteTargets(): void
  {
    $fixture = new ExactRouteFixture([new Route('/any/{id}', static fn (string $id): string => $id)]);
    $interfaces = $fixture->router->getInterfaces();
    self::assertCount(8, $interfaces);
    self::assertNotContains('*', array_map(static fn ($route): string => $route->method, $interfaces));
    self::assertNotContains('OPTIONS', array_map(static fn ($route): string => $route->method, $interfaces));
    $runtime = $this->runtime($fixture);
    $target = array_values(array_filter($interfaces, static fn ($route): bool => $route->method === 'GET'))[0];
    $runtime->setOverride('get-only', $target, 0);
    self::assertNotNull($this->dispatch($fixture, $runtime, ['path' => '/any/123']));
    self::assertNull($this->dispatch($fixture, $runtime, ['path' => '/any/123', 'method' => 'POST']));
  }

  /** @return ExactRouteFixture 订单接口与相邻静态、动态及别名路由 */
  private function fixture(): ExactRouteFixture
  {
    return new ExactRouteFixture([
      new Route(['/orders/{id}', '/alias/{id}'], static fn (string $id): string => $id, methods: ['GET']),
      new Route('/orders/search', static fn (): string => 'search', methods: ['GET']),
      new Route('/orders/{id}/items', static fn (string $id): string => $id, methods: ['GET']),
      new Route('/orders/{id}', static fn (string $id): string => $id, methods: ['POST']),
    ]);
  }

  /**
   * 创建隔离内存管理器
   * @param ExactRouteFixture $fixture 已完成目录
   * @return MonitorRuntime 管理器
   */
  private function runtime(ExactRouteFixture $fixture): MonitorRuntime
  {
    return new MonitorRuntime(new MonitorOptions(), $fixture->catalog, new MemoryStateStore(RuntimeState::initial(1e9)));
  }

  /**
   * 通过真实分发器执行并返回定稿
   * @param ExactRouteFixture $fixture 路由装配
   * @param MonitorRuntime $runtime 运行态
   * @param array{path:string,method?:string,domain?:string} $request 请求身份
   * @return \Viswoole\Monitor\Dto\SlowRequestSnapshot|null 快照
   */
  private function dispatch(ExactRouteFixture $fixture, MonitorRuntime $runtime, array $request): ?\Viswoole\Monitor\Dto\SlowRequestSnapshot
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true]), $writer, $runtime);
    $monitor->start($request['path'], static fn (): array => []);
    try { $fixture->router->dispatch($request['path'], $request['method'] ?? 'GET', $request['domain'] ?? 'localhost'); }
    catch (RouteNotFoundException $error) { self::assertNotSame('', $error->getMessage()); }
    $monitor->finish();
    return $writer->snapshots[0] ?? null;
  }
}
