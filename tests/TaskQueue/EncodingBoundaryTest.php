<?php
/**
 * 职责：断言真实 Redis 中的编码与主题边界；不模拟原子状态转换。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Cache\Driver\Redis\QueueRecord;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 每个断言对应一项输入或失败处理契约。
 */
final class EncodingBoundaryTest extends TestCase
{
    /**
     * @var array<string, bool> 场景结果
     */
    private static array $observations;

    /**
     * 运行独立真实存储场景。
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$observations = Support\ScenarioRunner::run('encoding-boundaries.php', 10);
    }

    /**
     * 验证边界行为。
     * @param string $behavior 观测项
     * @return void
     */
    #[DataProvider('provideBehaviors')]
    public function testBehavior(string $behavior): void
    {
        self::assertTrue(self::$observations[$behavior], $behavior);
    }

    /**
     * 损坏或非规范编码必须被拒绝，避免读取后无法通过条件提交。
     * @param mixed $encoded 损坏编码
     * @return void
     */
    #[DataProvider('provideInvalidErrors')]
    public function testInvalidErrorEncoding(mixed $encoded): void
    {
        $this->expectException(RuntimeException::class);
        QueueRecord::restoreError(['lastErrorBase64' => $encoded]);
    }

    /**
     * 提供类型错误、无效字节及可解码但不规范的输入。
     * @return iterable<string, array{mixed}> 无效编码
     */
    public static function provideInvalidErrors(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [0];
        yield 'invalid' => ['%%%'];
        yield 'whitespace' => ['a Gk='];
        yield 'unpadded' => ['aGk'];
    }

    /**
     * 枚举编码、错误存储和主题入口断言。
     * @return iterable<string, array{string}> 场景参数
     */
    public static function provideBehaviors(): iterable
    {
        foreach (['rejectfalse', 'rejectnull', 'rejectinteger', 'rejectarray', 'jsonFailureRejected',
            'binaryConsumerRetries', 'binaryConsumerReasonPreserved', 'binaryTerminalReasonPreserved',
            'binaryFailureReplay', 'binaryDeliveryFailure', 'emptyDeliveryFailure', 'zeroDeliveryFailure',
            'zeroTopicConsumed', 'zeroTopicWithoutQueue', 'registerRejected', 'hasRejected', 'emitRejected',
            'emitWaitRejected', 'emitsWaitRejected', 'emptyPersistRejected', 'missingPersistRejected',
            'integerPersistRejected', 'phpErrorReplaced', 'phpStaleFailureRejected', 'phpBinaryFailureDeleted',
            'jsonErrorReplaced', 'jsonStaleFailureRejected', 'jsonBinaryFailureDeleted'] as $name) {
            yield $name => [$name];
        }
    }
}
