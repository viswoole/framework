<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\Dto\BodySnapshot;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\ResponseCapture;
use Viswoole\Monitor\SnapshotBudget;

/** 职责：验证超限业务摘要、脱敏、预算和分段采集边界。 */
final class BodyTruncatorTest extends TestCase
{
  /** @return void 完整超限 JSON 保留业务码，同时保持截断状态。 */
  public function testCompleteJsonPreservesCodeAndRedactsSecrets(): void
  {
    $options = new MonitorOptions(['max_body_bytes' => 80, 'body_truncator' =>
      static function (string $body, array $context): ?array {
        self::assertTrue($context['is_complete']);
        self::assertSame(80, $context['max_bytes']);
        self::assertSame('application/json', $context['content_type']);
        return array_intersect_key(json_decode($body, true, 512, JSON_THROW_ON_ERROR), ['code' => true, 'token' => true]);
      }]);
    $capture = $this->createCapture($options);
    $raw = json_encode(['code' => 0, 'token' => 'secret', 'data' => str_repeat('x', 200)], JSON_THROW_ON_ERROR);
    $capture->appendResponse($raw, true);
    $body = $capture->snapshot()['body'];
    self::assertSame(['code' => 0, 'token' => '[REDACTED]'], $body['content']);
    self::assertSame(strlen($raw), $body['bytes']);
    $snapshot = new BodySnapshot($body);
    self::assertTrue($snapshot->isTruncated);
    self::assertSame($body['content'], $snapshot->content);
  }

  /** @return void 首次超限前缀只处理一次，后续片段仍累计字节数。 */
  public function testStreamingPrefixIsExplicitAndOnlyHandledOnce(): void
  {
    $calls = 0;
    $options = new MonitorOptions(['max_body_bytes' => 20, 'body_truncator' =>
      static function (string $body, array $context) use (&$calls): array {
        ++$calls;
        self::assertFalse($context['is_complete']);
        self::assertSame('{"code":200,"data":"partial', $body);
        return ['code' => 200];
      }]);
    $capture = $this->createCapture($options);
    $capture->appendResponse('{"code":200,');
    $capture->appendResponse('"data":"partial');
    $capture->appendResponse(' rest"}', true);
    $body = $capture->snapshot()['body'];
    self::assertSame(1, $calls);
    self::assertSame(['code' => 200], $body['content']);
    self::assertSame(strlen('{"code":200,"data":"partial rest"}'), $body['bytes']);
  }

  /** @return void 摘要也受字节上限约束，null 使用默认占位。 */
  public function testOversizedSummaryAndNullFallBack(): void
  {
    foreach ([null, ['message' => str_repeat('x', 100)]] as $summary) {
      $options = new MonitorOptions(['max_body_bytes' => 20,
        'body_truncator' => static fn (): ?array => $summary]);
      $safe = (new PayloadSanitizer($options))->sanitizeBody(str_repeat('x', 100), 'application/json');
      self::assertSame('[BODY_LIMIT]', $safe['content']);
      self::assertNull((new BodySnapshot($safe))->content);
    }
  }

  /** @return void 不需要裁剪或不允许采集时不调用用户代码。 */
  public function testHandlerDoesNotBypassCapturePolicies(): void
  {
    foreach ([[], ['capture_response' => false], ['privacy' => PrivacyOptions::disableBodies()]] as $overrides) {
      $calls = 0;
      $options = new MonitorOptions($overrides + ['max_body_bytes' => 20,
        'body_truncator' => static function () use (&$calls): array { ++$calls; return ['code' => 0]; }]);
      $capture = $this->createCapture($options);
      $capture->appendResponse($overrides === [] ? '{"code":0}' : str_repeat('x', 100), true);
      $capture->snapshot();
      self::assertSame(0, $calls);
    }
    $options = new MonitorOptions(['body_truncator' => static function (): array {
      self::fail('非结构化正文不能调用处理器');
    }]);
    self::assertSame('[UNSTRUCTURED_BODY]', (new PayloadSanitizer($options))->sanitizeBody('text', 'text/plain')['content']);
  }

  /** @return void 用户处理器异常和非法返回只告警并回退，不泄漏异常消息。 */
  public function testHandlerFailuresAreIsolated(): void
  {
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
      $warnings[] = $message;
      return true;
    });
    try {
      foreach ([static fn (): string => 'invalid', static fn (): never => throw new \RuntimeException('private-secret')] as $handler) {
        $options = new MonitorOptions(['max_body_bytes' => 20, 'body_truncator' => $handler]);
        $capture = $this->createCapture($options);
        $capture->appendResponse(str_repeat('x', 100), true);
        self::assertSame('[BODY_LIMIT]', $capture->snapshot()['body']['content']);
        self::assertTrue($capture->snapshot()['completed']);
      }
    } finally {
      restore_error_handler();
    }
    self::assertCount(2, $warnings);
    self::assertStringNotContainsString('private-secret', implode(' ', $warnings));
  }

  /** @return void 共享预算不能被自定义摘要绕过。 */
  public function testSummaryCannotBypassSharedBudget(): void
  {
    $options = new MonitorOptions(['max_snapshot_bytes' => 16384, 'max_body_bytes' => 40,
      'body_truncator' => static fn (): array => ['code' => 200]]);
    $budget = new SnapshotBudget($options->maxSnapshotBytes);
    $capture = new ResponseCapture($options, $budget, new PayloadSanitizer($options));
    $capture->updateResponse(['content_type' => 'application/json']);
    self::assertTrue($budget->replace('other', 8100));
    $capture->appendResponse(str_repeat('x', 100), true);
    $body = $capture->snapshot()['body'];
    self::assertIsString($body['content']);
    self::assertTrue($body['truncated']);
    self::assertTrue($budget->hasTruncated());
  }

  /** @return void 最后一段才超限时处理器取得完整 JSON，直接脱敏入口行为一致。 */
  public function testFinalChunkAndSanitizerUseCompleteBody(): void
  {
    $options = new MonitorOptions(['max_body_bytes' => 20, 'body_truncator' =>
      static function (string $body, array $context): ?array {
        self::assertTrue($context['is_complete']);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return ['code' => $decoded['code']];
      }]);
    $raw = '{"code":0,"data":"' . str_repeat('x', 40) . '"}';
    $capture = $this->createCapture($options);
    $capture->appendResponse(substr($raw, 0, 10));
    $capture->appendResponse(substr($raw, 10), true);
    self::assertSame(['code' => 0], $capture->snapshot()['body']['content']);
    $safe = (new PayloadSanitizer($options))->sanitizeBody($raw, 'application/json');
    self::assertSame(['code' => 0], $safe['content']);
  }

  /** @return void 非法配置在启动时暴露。 */
  public function testInvalidConfigurationIsRejected(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new MonitorOptions(['body_truncator' => new \stdClass()]);
  }

  /**
   * 构建隔离的 JSON 响应采集器。
   * @param MonitorOptions $options 当前用例配置
   * @return ResponseCapture 无外部 HTTP 依赖的采集器
   */
  private function createCapture(MonitorOptions $options): ResponseCapture
  {
    $capture = new ResponseCapture($options, new SnapshotBudget($options->maxSnapshotBytes), new PayloadSanitizer($options));
    $capture->updateResponse(['content_type' => 'application/json']);
    return $capture;
  }
}
