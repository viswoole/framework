<?php

declare(strict_types=1);

/* 职责：验证真实进程共享、并发发布及写入故障下保留旧提交。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Process;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\Runtime\SharedRows;
use Viswoole\Monitor\Runtime\SharedStateStore;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\RouteIdentity;

/** 在 fork 前创建 Table 与锁，测试不会启动或重启业务服务。 */
final class SharedRuntimeTest extends TestCase
{
  /** @return void 两个子进程同时发布规则，父进程可见完整结果及全局变更 */
  public function testConcurrentProcessesShareUpdatesWithoutLostRules(): void
  {
    $catalog = new InterfaceCatalog();
    $route = new RouteIdentity('GET', '/orders/{id}', ['*']);
    $catalog->register('orders', ['template' => $route->template, 'domains' => ['*'], 'methods' => ['GET']]);
    $catalog->complete();
    $runtime = new MonitorRuntime(new MonitorOptions(), $catalog);
    $runtimeId = $runtime->getState()->runtimeId;
    $processes = [];
    foreach (['a', 'b'] as $prefix) {
      $process = new Process(static function (Process $process) use ($runtime, $route, $prefix, $runtimeId): void {
        try {
          for ($index = 0; $index < 64; $index++) { $runtime->setOverride($prefix . $index, $route, 0); }
          $runtime->setThresholdMs(123.5);
          $process->exit($runtime->getState()->runtimeId === $runtimeId ? 0 : 2);
        } catch (\Throwable) { $process->exit(1); }
      }, false, 0, false);
      self::assertNotFalse($process->start());
      $processes[] = $process;
    }
    foreach ($processes as $process) {
      $status = Process::wait(true);
      self::assertNotFalse($status);
      self::assertSame(0, $status['code']);
      self::assertSame(0, $status['signal']);
    }
    self::assertCount(128, $runtime->getOverrides());
    self::assertSame(123.5, $runtime->getThresholdMs());
    self::assertSame($runtimeId, $runtime->getState()->runtimeId);
    self::assertSame(129, $runtime->getState()->version);
  }

  /** @return void 缓冲行或提交行失败都不改变原活动状态，后续恢复仍可发布 */
  public function testBufferAndCommitFailurePreserveOldState(): void
  {
    $rows = new class(4) extends SharedRows {
      public ?string $failedKey = null;
      public function write(string $key, string $payload): bool
      {
        return $key === $this->failedKey ? false : parent::write($key, $payload);
      }
    };
    $store = new SharedStateStore(1000, 4, $rows);
    $before = $store->read();
    foreach (['0:0', 'commit'] as $key) {
      $rows->failedKey = $key;
      try { $store->update($this->change(...)); self::fail('应模拟写入失败'); }
      catch (RuntimeException) { self::assertSame($before, $store->read()); }
    }
    $rows->failedKey = null;
    $store->update($this->change(...));
    self::assertSame(42.0, $store->read()->thresholdMs);
    self::assertSame(1, $store->read()->version);
  }

  /**
   * 测试用纯配置转换
   * @param RuntimeState $state 旧状态
   * @return RuntimeState 新状态
   */
  private function change(RuntimeState $state): RuntimeState
  {
    return new RuntimeState(['threshold_ms' => 42.0, 'runtime_id' => $state->runtimeId, 'version' => $state->version + 1]);
  }
}
