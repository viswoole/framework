<?php
/**
 * 职责：回归同步契约和实例处理器；不依赖 Redis。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 真实扩展返回类型优先于 IDE 辅助声明。
 */
final class ContractFixTest extends TestCase
{
    /**
     * @var array<string, mixed> 隔离场景观测
     */
    private static array $observations;

    /**
     * 仅初始化一次真实服务场景。
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$observations = Support\ScenarioRunner::run('runtime-contracts.php', 10);
    }

    /**
     * 验证调用契约。
     * @param string $behavior 场景名称
     * @return void
     */
    #[DataProvider('provideBehaviors')]
    public function testBehavior(string $behavior): void
    {
        self::assertTrue(self::$observations[$behavior], $behavior);
    }

    /**
     * 提供独立的契约断言名称。
     * @return iterable<string, array{string}> 场景参数
     */
    public static function provideBehaviors(): iterable
    {
        foreach (['instanceInjected', 'instanceIsolated', 'staticCallable', 'hiddenExcluded', 'zeroSync', 'zeroMulti', 'zeroCo'] as $name) {
            yield $name => [$name];
        }
        foreach (['array', 'int', 'true', 'null', 'string', 'false'] as $name) {
            yield 'return' . $name => ['return' . $name];
        }
    }
}
