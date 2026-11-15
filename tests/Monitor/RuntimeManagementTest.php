<?php

declare(strict_types=1);

/* 职责：验证精确规则管理、版本、请求固定及来源预算。 */

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use LogicException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\Runtime\MemoryStateStore;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\RouteIdentity;

/** 纯内存时钟测试，不依赖睡眠或业务存储。 */
final class RuntimeManagementTest extends TestCase
{
  /** @return void 只有唯一已注册接口可发布，目录初始化前明确拒绝 */
  public function testDirectoryValidationAndNoPathRules(): void
  {
    $catalog = new InterfaceCatalog();
    $runtime = $this->runtime($catalog);
    $route = new RouteIdentity('GET', '/orders/{id}', ['*']);
    try { $runtime->setOverride('r', $route, 0); self::fail('目录未初始化'); }
    catch (LogicException) { self::assertSame(0, $runtime->getState()->version); }
    $catalog->complete();
    try { $runtime->setOverride('r', $route, 0); self::fail('未知接口'); }
    catch (InvalidArgumentException) { self::assertSame([], $runtime->getOverrides()); }
    $this->register($catalog, $route);
    self::assertSame($route->key(), $runtime->setOverride('r', $route, 0)->route->key());
    $catalog->register('another-slot', ['template' => $route->template, 'domains' => ['*'], 'methods' => ['GET']]);
    $this->expectException(InvalidArgumentException::class);
    $runtime->setOverride('r', $route, 0);
  }

  /** @return void 单请求分别命中真正达标的规则与全局门槛 */
  public function testIndependentSourcesAndExactThresholdBoundary(): void
  {
    [$runtime, $route] = $this->configured();
    $runtime->setOverride('second', $route, 500);
    $first = $runtime->setOverride('first', $route, 100.5);
    $trace = $this->trace($runtime, $route);
    self::assertNull($trace->snapshot($trace->startedAt + 100_499_999));
    $snapshot = $trace->snapshot($trace->startedAt + 100_500_000);
    self::assertFalse($snapshot->capture->matchedGlobal);
    self::assertCount(1, $snapshot->capture->matchedOverrides);
    self::assertSame($first->version, $snapshot->capture->matchedOverrides[0]->version);
    $snapshot = $trace->snapshot($trace->startedAt + 1_000_000_000);
    self::assertTrue($snapshot->capture->matchedGlobal);
    self::assertSame(['first', 'second'], array_map(static fn ($match): string => $match->id, $snapshot->capture->matchedOverrides));
    self::assertSame($runtime->getState()->runtimeId, $snapshot->capture->runtimeId);
    self::assertSame($runtime->getState()->version, $snapshot->capture->configVersion);
  }

  /** @return void 更新、删除、重建不会影响在途请求，版本在本实例内不复用 */
  public function testFrozenPolicyAndVersions(): void
  {
    [$runtime, $route] = $this->configured();
    $before = $runtime->setOverride('r', $route, 100);
    $trace = $this->trace($runtime, $route);
    $runtime->setThresholdMs(10);
    self::assertTrue($runtime->removeOverride('r'));
    $version = $runtime->getState()->version;
    self::assertFalse($runtime->removeOverride('r'));
    self::assertSame($version, $runtime->getState()->version);
    $after = $runtime->setOverride('r', $route, 0);
    self::assertGreaterThan($before->version, $after->version);
    $snapshot = $trace->snapshot($trace->startedAt + 200_000_000);
    self::assertSame(1000.0, $snapshot->capture->globalThresholdMs);
    self::assertSame($before->version, $snapshot->capture->matchedOverrides[0]->version);
    self::assertNotSame($runtime->getState()->runtimeId, RuntimeState::initial(1000)->runtimeId);
  }

  /** @return void 容量满时替换有效，非法替换保留旧值；删除后可以重新新增 */
  public function testCapacityAndInvalidMutationPreserveState(): void
  {
    [$runtime, $route] = $this->configured(['override_capacity' => 1]);
    $runtime->setOverride('r', $route, 100);
    $replacement = $runtime->setOverride('r', $route, 50);
    foreach (['other' => 0, 'r' => -1] as $id => $threshold) {
      $before = $runtime->getState();
      try { $runtime->setOverride($id, $route, $threshold); self::fail('应拒绝变更'); }
      catch (OverflowException|InvalidArgumentException) { self::assertSame($before, $runtime->getState()); }
    }
    self::assertSame($replacement, $runtime->getOverrides()[0]);
    $runtime->removeOverride('r');
    $runtime->setOverride('new', $route, 0);
    self::assertCount(1, $runtime->getOverrides());
    foreach ([INF, NAN, -1.0] as $threshold) {
      try { $runtime->setThresholdMs($threshold); self::fail('非法阈值'); }
      catch (InvalidArgumentException) { self::assertSame(1000.0, $runtime->getThresholdMs()); }
    }
  }

  /** @return void 来源完整保留，新增同接口规则超预算时原子拒绝 */
  public function testBudgetAndHistoricalSnapshotCompatibility(): void
  {
    [$runtime, $route] = $this->configured(['max_snapshot_bytes' => 16384]);
    for ($index = 0; $index < 256; $index++) {
      $before = $runtime->getState();
      try { $runtime->setOverride(str_pad((string)$index, 64, 'x'), $route, 0); }
      catch (OverflowException) {
        self::assertSame($before, $runtime->getState());
        $trace = $this->trace($runtime, $route, new MonitorOptions(['max_snapshot_bytes' => 16384]));
        $snapshot = $trace->snapshot();
        self::assertCount(count($before->rules), $snapshot->capture->matchedOverrides);
        self::assertLessThanOrEqual(16384, strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)));
        $legacy = $snapshot->toArray();
        unset($legacy['capture']);
        $legacy['schema_version'] = 1;
        self::assertNull((new SlowRequestSnapshot($legacy))->capture);
        self::assertSame($legacy, (new SlowRequestSnapshot($legacy))->toArray());
        return;
      }
    }
    self::fail('最小预算应拒绝过多同接口来源');
  }

  /** @return void 冲突重试时，另一管理者已删除规则则本次应返回 false */
  public function testDeleteResultReflectsRetriedState(): void
  {
    [$runtime, $route] = $this->configured();
    $runtime->setOverride('r', $route, 0);
    $store = new class($runtime->getState()) implements \Viswoole\Monitor\Runtime\StateStore {
      public function __construct(private RuntimeState $state) {}
      public function read(): RuntimeState { return $this->state; }
      public function update(callable $change): RuntimeState
      {
        $change($this->state);
        // 模拟第一次转换后其他管理者抢先提交删除，使本次发布发生版本冲突。
        $this->state = new RuntimeState([
          'threshold_ms' => $this->state->thresholdMs, 'runtime_id' => $this->state->runtimeId,
          'version' => $this->state->version + 1,
        ]);
        $this->state = $change($this->state);
        return $this->state;
      }
    };
    $manager = new MonitorRuntime(new MonitorOptions(), new InterfaceCatalog(), $store);
    self::assertFalse($manager->removeOverride('r'));
    self::assertSame([], $manager->getOverrides());
  }

  /**
   * 创建就绪目录及管理器
   * @param array<string,mixed> $config 预算或容量
   * @return array{MonitorRuntime,RouteIdentity} 测试环境
   */
  private function configured(array $config = []): array
  {
    $catalog = new InterfaceCatalog();
    $route = new RouteIdentity('GET', '/orders/{id}', ['*']);
    $this->register($catalog, $route);
    $catalog->complete();
    return [$this->runtime($catalog, $config), $route];
  }

  /**
   * 创建内存管理器
   * @param InterfaceCatalog $catalog 目录
   * @param array<string,mixed> $config 静态配置
   * @return MonitorRuntime 管理器
   */
  private function runtime(InterfaceCatalog $catalog, array $config = []): MonitorRuntime
  {
    return new MonitorRuntime(new MonitorOptions($config), $catalog, new MemoryStateStore(RuntimeState::initial(1000)));
  }

  /**
   * 注册接口元数据
   * @param InterfaceCatalog $catalog 目录
   * @param RouteIdentity $route 身份
   * @return void
   */
  private function register(InterfaceCatalog $catalog, RouteIdentity $route): void
  {
    $catalog->register($route->key(), ['template' => $route->template, 'domains' => $route->domainConstraints, 'methods' => [$route->method]]);
  }

  /**
   * 固定请求策略
   * @param MonitorRuntime $runtime 管理器
   * @param RouteIdentity $route 接口
   * @param MonitorOptions|null $options 预算
   * @return RequestTrace 请求状态
   */
  private function trace(MonitorRuntime $runtime, RouteIdentity $route, ?MonitorOptions $options = null): RequestTrace
  {
    $trace = new RequestTrace($options ?? new MonitorOptions(), []);
    $trace->attachRuntime($runtime);
    $trace->freezeCapturePolicy($route);
    return $trace;
  }
}
