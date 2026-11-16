<?php

/*
 * 职责：验证异步投递的受理边界；通过隔离场景使用真实 Redis，不启动业务应用。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 受理成功、明确拒收和提交未知必须可区分。
 */
final class AcceptanceTest extends TestCase
{
  /**
   * @var array<string, bool> 隔离场景观测
   */
  private static array $observations;

  /**
   * 执行受理故障场景
   *
   * 隔离门面替身，存储仍使用生产 Lua。
   *
   * @return void 场景观测已加载
   */
  public static function setUpBeforeClass(): void
  {
    self::$observations = Support\ScenarioRunner::run('acceptance.php', 10);
  }

  /**
   * 验证受理契约
   *
   * @param string $behavior 独立行为名称
   * @return void 断言行为符合契约
   */
  #[DataProvider('provideBehaviors')]
  public function testAcceptance(string $behavior): void
  {
    self::assertTrue(self::$observations[$behavior], $behavior);
  }

  /**
   * 提供受理边界
   *
   * @return iterable<string, array{string}> 场景名称
   */
  public static function provideBehaviors(): iterable
  {
    foreach (['accepted', 'ipcFalse', 'ipcException', 'readFailure', 'retryWriteFailure',
      'unknownCommitted', 'unknownNotCommitted', 'unknownFalse', 'connectionRejected', 'serializationRejected',
      'directZero', 'directFalse', 'directException', 'stoppingRejected', 'recoveredOnce'] as $name) {
      yield $name => [$name];
    }
  }
}
