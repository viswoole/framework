<?php

declare(strict_types=1);

/* 职责：验证请求 ID 默认格式、自定义 callable、配置校验与失败旁路。 */

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/**
 * 请求 ID 生成契约
 *
 * 自定义生成器必须逐请求调用；默认随机 ID 不宣称绝对唯一。
 */
final class RequestIdTest extends TestCase
{
  /**
   * 默认 ID 保留 128 位随机十六进制格式
   *
   * @return void
   */
  public function testDefaultRandomFormat(): void
  {
    self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (new RequestTrace(new MonitorOptions(), []))->requestId);
  }

  /**
   * 支持 PHP 的全部常见 callable 形式
   *
   * @return void
   */
  public function testCallableFormsAndIntegerSnowflakeResult(): void
  {
    $object = new RequestIdCallableFixture();
    foreach ([
      static fn (): string => 'custom',
      RequestIdCallableFixture::class . '::generate',
      [RequestIdCallableFixture::class, 'generate'],
      [$object, 'create'],
      $object,
      RequestIdCallableFixture::generate(...),
      __NAMESPACE__ . '\\generateFixtureRequestId',
    ] as $generator) {
      $trace = new RequestTrace(new MonitorOptions(['request_id' => ['generator' => $generator]]), []);
      self::assertContains($trace->requestId, ['custom', '1234567890123456789']);
    }
  }

  /**
   * 同一请求只生成一次，关闭及排除路径不调用生成器
   *
   * @return void
   */
  public function testGeneratorRunsOncePerCollectedRequest(): void
  {
    $calls = 0;
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0, 'exclude_paths' => ['/skip'],
      'request_id' => ['generator' => static function () use (&$calls): int { return ++$calls; }]]);
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor($options, $writer);
    try {
      $monitor->start('/skip', static fn (): array => []);
      self::assertSame(0, $calls);
      $monitor->start('/ok', static fn (): array => []);
      self::assertSame('1', TraceContext::current()->requestId);
      $monitor->finish();
      self::assertSame('1', $writer->records[0]['request_id']);
      self::assertSame(1, $calls);
    } finally {
      TraceContext::clear();
    }
  }

  /**
   * 拒绝无效 callable 和标头名
   *
   * @return void
   */
  public function testInvalidGeneratorIsRejectedDuringConfiguration(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new MonitorOptions(['request_id' => ['generator' => new \stdClass()]]);
  }

  /**
   * 拒绝标头注入
   *
   * @return void
   */
  public function testInvalidHeaderNameIsRejected(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new MonitorOptions(['request_id' => ['response_header' => "X-Request-Id\r\nInjected"]]);
  }

  /**
   * 生成器失败不伪造替代 ID
   *
   * 由监控旁路记录错误并跳过该请求，避免随机值破坏使用方的严格唯一承诺。
   *
   * @return void
   */
  public function testFailingGeneratorSkipsMonitoringWithoutRandomFallback(): void
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'request_id' => [
      'generator' => static function (): never { throw new RuntimeException('synthetic-private'); },
    ]]), $writer);
    $warnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
      $warnings[] = $errstr;
      return true;
    });
    try {
      $monitor->start('/failure', static fn (): array => []);
      self::assertNull(TraceContext::current());
      self::assertSame([], $writer->records);
      $reported = implode("\n", $warnings);
      self::assertStringContainsString(RuntimeException::class, $reported);
      self::assertStringNotContainsString('synthetic-private', $reported);
    } finally {
      restore_error_handler();
    }
  }

  /**
   * 生成器返回值必须适合日志与响应头
   *
   * @return void
   */
  public function testInvalidGeneratedValuesAreRejected(): void
  {
    foreach ([null, false, 1.5, [], '', "line\r\nbreak", str_repeat('a', 257), '非ASCII'] as $value) {
      $options = new MonitorOptions(['request_id' => ['generator' => static fn (): mixed => $value]]);
      try {
        new RequestTrace($options, []);
        self::fail('非法请求 ID 应被拒绝');
      } catch (\UnexpectedValueException) {
        self::assertTrue(true);
      }
    }
  }

}

/** 合成生成器覆盖静态方法、实例方法和可调用对象，不访问真实发号服务。 */
final class RequestIdCallableFixture
{
  /**
   * 返回合成雪花 ID
   *
   * @return int 用于验证字符串转换的发号结果
   */
  public static function generate(): int { return 1234567890123456789; }
  /**
   * 返回合成字符串 ID
   *
   * @return string 无外部依赖的发号结果
   */
  public function create(): string { return 'custom'; }
  /**
   * 返回合成字符串 ID
   *
   * @return string 无外部依赖的发号结果
   */
  public function __invoke(): string { return 'custom'; }
}

/**
 * 返回合成函数形式 ID
 *
 * @return string 无外部依赖的发号结果
 */
function generateFixtureRequestId(): string { return 'custom'; }
