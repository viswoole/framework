<?php

declare(strict_types=1);

/* 职责：复现路径排除、内部操作分类和正文关闭采集三个审查问题。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 审查边界回归
 *
 * 排除策略应遵循路由路径语义，输出脱敏不能改变内部诊断事实。
 */
final class ReviewRegressionTest extends TestCase
{
  /**
   * 清理采集上下文
   *
   * @return void
   */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /**
   * 等价路径均应排除
   *
   * 百分号编码、尾斜线及默认不敏感的大小写不能绕过敏感接口的排除。
   *
   * @return void
   */
  public function testEquivalentPathsAreExcluded(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/private/login', '/private/session/*']]);
    foreach (['/private/login', '/private/%6cogin', '/PRIVATE/LOGIN', '/private/login/', '/PRIVATE/SESSION/42/'] as $path) {
      self::assertFalse($options->shouldCollect($path), $path);
    }
    self::assertTrue($options->shouldCollect('/private/logout'));
  }

  /**
   * 分类不受自定义脱敏影响
   *
   * 明细保留、条数超限和总预算超限必须使用同一个可信操作类型。
   *
   * @return void
   */
  public function testSensitiveTypeRuleCannotChangeSummaryClassification(): void
  {
    foreach ([0, 1] as $limit) {
      $trace = new RequestTrace(new MonitorOptions([
        'threshold_ms' => 0, 'max_operations' => $limit, 'privacy' => PrivacyOptions::redact(['type']),
      ]), []);
      TraceContext::attach($trace);
      TraceContext::measure(['type' => 'database', 'sql' => 'SELECT 1'], static fn (): bool => true);
      $record = $trace->finish();
      self::assertSame(['database'], array_keys($record['operation_summary']));
      self::assertSame(1, $record['operation_summary']['database']['count']);
      if ($limit === 1) { self::assertSame('[REDACTED]', $record['operations'][0]['type']); }
    }
  }

  /**
   * 请求与响应统一隐藏正文容器
   *
   * 即使正文被拆分发送或达到上限，body 敏感规则也不能被绕过。
   *
   * @return void
   */
  public function testCaptureSwitchDisablesBothBodies(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'privacy' => PrivacyOptions::disableBodies()]), [
      'body' => ['content' => ['name' => 'synthetic']],
    ]);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->appendResponse('{"name":');
    $trace->appendResponse('"synthetic"}', true);
    $record = $trace->finish();
    self::assertSame('[CAPTURE_DISABLED]', $record['request']['body']['content']);
    self::assertSame('[CAPTURE_DISABLED]', $record['response']['body']['content']);
    self::assertStringNotContainsString('synthetic', json_encode($record, JSON_THROW_ON_ERROR));
    self::assertTrue($record['response']['completed']);
  }

  /**
   * 排除路径遵循显式区分大小写设置
   *
   * @return void
   */
  public function testCaseSensitiveRouterPreservesDistinctPaths(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/private/login']], true);
    self::assertFalse($options->shouldCollect('/private/%6cogin/'));
    self::assertTrue($options->shouldCollect('/PRIVATE/LOGIN'));
  }

  /**
   * 不解码路径分隔符或重复解码
   *
   * @return void
   */
  public function testEncodedSlashAndDoubleEncodingDoNotChangeResourceBoundary(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/private/login', '/']]);
    self::assertTrue($options->shouldCollect('/private%2Flogin'));
    self::assertTrue($options->shouldCollect('/private/%256cogin'));
    self::assertFalse($options->shouldCollect('/'));
    $encodedPattern = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/PRIVATE/%6cogin/']]);
    self::assertFalse($encodedPattern->shouldCollect('/private/login'));
  }

  /**
   * 超限正文依然应用关闭采集
   *
   * @return void
   */
  public function testBodyRuleAppliesAfterTruncationAndCaptureSwitch(): void
  {
    foreach ([true, false] as $isCaptured) {
      $trace = new RequestTrace(new MonitorOptions([
        'threshold_ms' => 0, 'max_body_bytes' => 1, 'capture_response' => $isCaptured, 'privacy' => PrivacyOptions::disableBodies(),
      ]), []);
      $trace->appendResponse('synthetic', true);
      self::assertSame('[CAPTURE_DISABLED]', $trace->finish()['response']['body']['content']);
    }
  }

  /**
   * 总预算超限后的分类保持一致
   *
   * @return void
   */
  public function testBudgetDroppedOperationsKeepTheirInternalType(): void
  {
    $trace = new RequestTrace(new MonitorOptions([
      'threshold_ms' => 0, 'max_snapshot_bytes' => 16384, 'privacy' => PrivacyOptions::redact(['type']),
    ]), []);
    TraceContext::attach($trace);
    for ($index = 0; $index < 30; $index++) {
      TraceContext::measure(['type' => 'cache', 'key' => 'synthetic', 'extra' => str_repeat('x', 4000)], static fn (): bool => true);
    }
    $record = $trace->finish();
    self::assertGreaterThan(0, $record['dropped_operations']);
    self::assertSame(['cache'], array_keys($record['operation_summary']));
    self::assertSame(30, $record['operation_summary']['cache']['count']);
  }

}
