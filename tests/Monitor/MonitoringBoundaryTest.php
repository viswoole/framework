<?php

declare(strict_types=1);

/* 职责：复现慢请求采集的安全、总预算及超限汇总边界，不访问外部服务。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 慢请求采集边界回归
 *
 * 使用合成凭据与受控计时，保证采集不泄漏原值且不会丢失超限操作统计。
 */
final class MonitoringBoundaryTest extends TestCase
{
  /**
   * 清理测试上下文
   *
   * 防止测试失败后污染同进程其他用例。
   *
   * @return void
   */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /**
   * 隐藏无法结构化脱敏的正文
   *
   * 纯文本、HTML 和 SSE 默认只提供大小，不保存可携带凭证的内容。
   *
   * @return void
   */
  public function testUnstructuredBodiesAreOmittedByDefault(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions());
    foreach (['text/plain', 'text/html', 'text/event-stream', 'application/octet-stream'] as $type) {
      $body = $sanitizer->sanitizeBody('password=synthetic-private', $type);
      self::assertSame('[UNSTRUCTURED_BODY]', $body['content']);
      self::assertSame(26, $body['bytes']);
    }
  }

  /**
   * 隐藏验证码和票据
   *
   * 内置规则应覆盖常见登录与验证码参数，而不依赖每个业务重复配置。
   *
   * @return void
   */
  public function testVerificationCredentialsAreRedacted(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions());
    $safe = $sanitizer->sanitize(['smsCode' => 'synthetic', 'ticket' => 'synthetic', 'captcha' => 'synthetic']);
    self::assertSame(['smsCode' => '[REDACTED]', 'ticket' => '[REDACTED]', 'captcha' => '[REDACTED]'], $safe);
  }

  /**
   * 短敏感词仅按字段名完全相等命中，避免 otp 命中 desktop 类误伤
   */
  public function testShortSensitiveWordsRequireExactMatch(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions(['sensitive_keys' => ['otp']]));
    $safe = $sanitizer->sanitize(['otp' => 'synthetic', 'desktop' => 'visible']);
    self::assertSame('[REDACTED]', $safe['otp']);
    self::assertSame('visible', $safe['desktop']);
  }

  /**
   * 缓存键仅保留稳定摘要
   *
   * 命名空间中的凭证不能作为普通 key 字段原样持久化；同键仍可关联。
   *
   * @return void
   */
  public function testCacheKeysAndTagsDoNotExposeEmbeddedCredentials(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
    TraceContext::attach($trace);
    $metadata = ['type' => 'cache', 'operation' => 'tag.get', 'key' => 'session:synthetic-private', 'tags' => ['synthetic-tag']];
    TraceContext::measure($metadata, static fn (): bool => true);
    TraceContext::measure($metadata, static fn (): bool => true);
    $record = $trace->finish();
    self::assertStringNotContainsString('synthetic-', json_encode($record, JSON_THROW_ON_ERROR));
    self::assertSame($record['operations'][0]['key'], $record['operations'][1]['key']);
  }

  /**
   * 快照共享总字节预算
   *
   * 多条大 SQL 不能各自获得完整请求预算，序列化后的快照必须仍在总上限内。
   *
   * @return void
   */
  public function testSnapshotBudgetIsSharedAcrossOperations(): void
  {
    $trace = new RequestTrace(new MonitorOptions([
      'threshold_ms' => 0, 'max_snapshot_bytes' => 32768, 'max_body_bytes' => 8192,
    ]), ['path' => '/budget']);
    TraceContext::attach($trace);
    for ($index = 0; $index < 200; $index++) {
      TraceContext::measure(['type' => 'database', 'sql' => str_repeat('x', 8192)], static fn (): bool => true);
    }
    $record = $trace->finish();
    self::assertLessThanOrEqual(32768, strlen(json_encode($record, JSON_THROW_ON_ERROR)));
    self::assertTrue($record['snapshot_truncated']);
    self::assertGreaterThan(0, $record['dropped_operations']);
    self::assertSame(200, $record['operation_summary']['database']['count']);
  }

  /**
   * 超限操作仍计时并统计失败
   *
   * 最慢操作可能在明细配额耗尽后发生，不能因省略明细而省略诊断汇总。
   *
   * @return void
   */
  public function testDroppedOperationStillContributesToSummary(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'max_operations' => 0]), []);
    TraceContext::attach($trace);
    $failure = new RuntimeException('synthetic');
    try {
      TraceContext::measure(['type' => 'database'], static function () use ($failure): never {
        usleep(2000);
        throw $failure;
      });
    } catch (RuntimeException $caught) {
      self::assertSame($failure, $caught);
    }
    $summary = $trace->finish()['operation_summary']['database'];
    self::assertSame(1, $summary['count']);
    self::assertSame(1, $summary['failed_count']);
    self::assertGreaterThanOrEqual(1.0, $summary['duration_ms']);
    self::assertSame($summary['duration_ms'], $summary['dropped_duration_ms']);
  }
}
