<?php
/**
 * 职责：使用 PHPUnit 验证隔离 PHP 场景，不在测试进程中初始化 Swoole 服务。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ScenarioRunner.php';

/**
 * 验证队列审查回归的真实 Redis 场景。
 */
final class QueueRegressionTest extends TestCase
{
    /**
     * @var array<string, mixed> 独立进程返回的观测值
     */
    private static array $observations;

    /**
     * 只运行一次场景，避免重复执行耗时的并发和租约验证。
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$observations = Support\ScenarioRunner::run('review-regressions.php', 25);
    }

    /**
     * 验证每项消费契约，并保留独立失败用例名称。
     * @param string $behavior 观测项名称
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideBehaviors')]
    public function testBehavior(string $behavior): void
    {
        self::assertTrue(self::$observations[$behavior], $behavior);
    }

    /**
     * 提供场景契约名称，不在收集测试时启动外部服务。
     * @return iterable<string, array{string}> 场景参数
     */
    public static function provideBehaviors(): iterable
    {
        yield 'ackDuringRenewal' => ['ackDuringRenewal'];
        yield 'ackState' => ['ackState'];
        yield 'failDuringRenewal' => ['failDuringRenewal'];
        yield 'failState' => ['failState'];
        yield 'renewDuringRenewal' => ['renewDuringRenewal'];
        yield 'renewState' => ['renewState'];
        yield 'objectConsumed' => ['objectConsumed'];
        yield 'objectBytesPreserved' => ['objectBytesPreserved'];
        yield 'objectReplayed' => ['objectReplayed'];
        yield 'invalidConfigRejectedTwice' => ['invalidConfigRejectedTwice'];
        yield 'correctedConfigCached' => ['correctedConfigCached'];
        yield 'correctedPolicyApplied' => ['correctedPolicyApplied'];
        yield 'retryConfigAtomic' => ['retryConfigAtomic'];
        yield 'payloadFormatsPreserved' => ['payloadFormatsPreserved'];
        yield 'customSerializationPreserved' => ['customSerializationPreserved'];
        yield 'strictRevisionRejected' => ['strictRevisionRejected'];
        yield 'deletedTaskNotRecreated' => ['deletedTaskNotRecreated'];
    }

}
