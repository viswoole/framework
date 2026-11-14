<?php

declare(strict_types=1);

/* 职责：验证请求路径脱敏叠加、隔离和编码路径语义。 */

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;

/**
 * 路径脱敏策略回归
 *
 * 全局及所有命中路径规则取并集，不能撤销内置保护或污染下一请求。
 */
final class PathSensitiveKeysTest extends TestCase
{
  /**
   * 叠加规则贯穿参数、响应头、正文及数据库绑定
   *
   * @return void
   */
  public function testAllMatchedRulesApplyAcrossCaptureSources(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys' => ['global_field'], 'sensitive_keys_by_path' => [
      '/api/*' => ['code'], '/api/auth/*' => ['mobile'], '/api/auth/login' => ['x-private'],
    ]]);
    $trace = new RequestTrace($options, ['path' => '/API/AUTH/%6cogin/',
      'query' => ['password' => 'synthetic', 'code' => 'synthetic', 'mobile' => 'synthetic', 'global_field' => 'synthetic']]);
    TraceContext::attach($trace);
    try {
      $trace->updateResponseHeader('X-Private', 'synthetic');
      $trace->updateResponse(['content_type' => 'application/json']);
      $trace->appendResponse('{"mobile":"synthetic"}', true);
      TraceContext::measure(['type' => 'database', 'bindings' => ['code' => 'synthetic']], static fn (): bool => true);
      $record = $trace->finish();
      self::assertStringNotContainsString('synthetic', json_encode($record, JSON_THROW_ON_ERROR));
      self::assertSame('[REDACTED]', $record['response']['headers']['x-private']);
      self::assertSame('[REDACTED]', $record['operations'][0]['bindings']['code']);
    } finally {
      TraceContext::clear();
    }
  }

  /**
   * 同一配置创建的不同路径请求不共享临时规则
   *
   * @return void
   */
  public function testPathRulesDoNotLeakIntoOtherRequests(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys_by_path' => ['/private/*' => ['name']]]);
    $private = new RequestTrace($options, ['path' => '/private/detail', 'query' => ['name' => 'visible']]);
    $public = new RequestTrace($options, ['path' => '/public/detail', 'query' => ['name' => 'visible']]);
    self::assertSame('[REDACTED]', $private->finish()['request']['query']['name']);
    self::assertSame('visible', $public->finish()['request']['query']['name']);
    self::assertNotContains('name', $options->sensitiveKeys);
  }

  /**
   * 支持按路径隐藏整组正文
   *
   * @return void
   */
  public function testPathCanHideWholeResponseBody(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys_by_path' => ['/private/*' => ['body']]]);
    $trace = new RequestTrace($options, ['path' => '/private/detail']);
    $trace->appendResponse('{"name":"synthetic"}', true);
    self::assertSame('[REDACTED]', $trace->finish()['response']['body']);
  }

  /**
   * 路径规则错误启动期拒绝
   *
   * @return void
   */
  public function testInvalidPathRuleIsRejected(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new MonitorOptions(['sensitive_keys_by_path' => ['/private/*' => [null]]]);
  }

  /**
   * 大小写及编码斜线保持资源边界
   *
   * @return void
   */
  public function testPathRulesRespectRouterCaseAndEncodedSlashes(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys_by_path' => ['/private/login' => ['name']]], true);
    foreach (['/PRIVATE/LOGIN', '/private%2Flogin'] as $path) {
      $trace = new RequestTrace($options, ['path' => $path, 'query' => ['name' => 'visible']]);
      self::assertSame('visible', $trace->finish()['request']['query']['name']);
    }
  }

  /**
   * 并发请求各自使用路径规则
   *
   * @return void
   */
  public function testConcurrentRequestsKeepIndependentPolicies(): void
  {
    $writer = new InMemoryTraceWriter();
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0,
      'sensitive_keys_by_path' => ['/private/*' => ['name']]]);
    $monitor = new \Viswoole\Monitor\SlowRequestMonitor($options, $writer);
    \Swoole\Coroutine\run(static function () use ($monitor): void {
      $done = new \Swoole\Coroutine\Channel(2);
      foreach (['/private/detail', '/public/detail'] as $path) {
        \Swoole\Coroutine::create(static function () use ($monitor, $path, $done): void {
          $monitor->start($path, static fn (): array => ['query' => ['name' => 'visible']]);
          \Swoole\Coroutine::sleep(0.001);
          TraceContext::current()->updateResponse(['content_type' => 'application/json']);
          TraceContext::current()->appendResponse('{"name":"visible"}', true);
          $monitor->finish();
          $done->push(true);
        });
      }
      $done->pop();
      $done->pop();
    });
    self::assertCount(2, $writer->records);
    foreach ($writer->records as $record) {
      $expected = $record['request']['path'] === '/private/detail' ? '[REDACTED]' : 'visible';
      self::assertSame($expected, $record['request']['query']['name']);
      self::assertSame($expected, $record['response']['body']['content']['name']);
    }
  }

}
