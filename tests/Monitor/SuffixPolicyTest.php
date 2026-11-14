<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/** 验证采集前的路径保护覆盖伪静态候选，且只解码一次。 */
final class SuffixPolicyTest extends TestCase
{
  /** @return void 清理非协程测试的采集状态。 */
  protected function tearDown(): void
  {
    TraceContext::clear();
  }

  /** @return void 排除必须在读取请求快照之前执行，不能收尾时才丢弃记录。 */
  public function testExcludedSuffixDoesNotReadOrPersistRequest(): void
  {
    $writer = new InMemoryTraceWriter();
    $options = new MonitorOptions(['enabled' => true, 'threshold_ms' => 0, 'exclude_paths' => ['/private/login']]);
    $monitor = new SlowRequestMonitor($options, $writer);
    $hasCaptured = false;
    $monitor->start('/private/login.html', static function () use (&$hasCaptured): array {
      $hasCaptured = true;
      return [];
    });
    self::assertFalse($hasCaptured);
    self::assertNull(TraceContext::current());
    $monitor->finish();
    self::assertSame([], $writer->records);
  }

  /** @return void 编码、大小写和尾斜线不能绕过排除规则。 */
  public function testSuffixCandidatesAreExcludedBeforeCapture(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/private/login']]);
    foreach (['/private/login.html', '/PRIVATE/%6cogin%2Ehtml/'] as $path) {
      self::assertFalse($options->shouldCollect($path), $path);
    }
    foreach (['/private/logout.html', '/private%2Flogin.html', '/private/%256cogin.html'] as $path) {
      self::assertTrue($options->shouldCollect($path), $path);
    }
  }

  /** @return void 完整路径与去后缀规则均生效，且贯穿请求与响应。 */
  public function testBothCandidatesContributeSensitiveFields(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys_by_path' => [
      '/private/login' => ['mobile'], '/private/login.html' => ['code'],
    ]]);
    $trace = new RequestTrace($options, ['path' => '/PRIVATE/%6cogin%2Ehtml/',
      'query' => ['mobile' => 'private-mobile', 'code' => 'private-code']]);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->appendResponse('{"mobile":"private-mobile","code":"private-code"}', true);
    $record = $trace->finish();
    $expected = ['mobile' => '[REDACTED]', 'code' => '[REDACTED]'];
    self::assertSame($expected, $record['request']['query']);
    self::assertSame($expected, $record['response']['body']['content']);
  }

  /** @return void 路由大小写和编码边界不会因新增候选改变。 */
  public function testCandidateMatchingPreservesResourceBoundaries(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/private/login'],
      'sensitive_keys_by_path' => ['/private/login' => ['mobile']]], true);
    self::assertFalse($options->shouldCollect('/private/login.html'));
    foreach (['/PRIVATE/login.html', '/private%2Flogin.html', '/private/%256cogin.html', '/private/login.html/child'] as $path) {
      self::assertTrue($options->shouldCollect($path), $path);
      self::assertNotContains('mobile', $options->sensitiveKeysForPath($path));
    }
  }

  /** @return void 点号属于完整路径时也采用保护并集，不撤销已有规则。 */
  public function testLiteralDottedPathsReceiveConservativeProtection(): void
  {
    $options = new MonitorOptions(['enabled' => true, 'exclude_paths' => ['/user/john'],
      'sensitive_keys_by_path' => ['/user/john' => ['mobile'], '/user/john.doe' => ['code']]]);
    self::assertFalse($options->shouldCollect('/user/john.doe'));
    self::assertContains('mobile', $options->sensitiveKeysForPath('/user/john.doe'));
    self::assertContains('code', $options->sensitiveKeysForPath('/user/john.doe'));
  }
}
