<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\ResponseCapture;
use Viswoole\Monitor\SnapshotBudget;

/** 职责：验证响应元数据兜底不能绕过预算，也不能改变正文的解析语义。 */
final class ResponseMetadataBudgetTest extends TestCase
{
  /** @return void 复现操作明细填满后，超长 Content-Type 通过兜底突破总预算。 */
  public function testLongContentTypeCannotEscapeTotalBudget(): void
  {
    $trace = new RequestTrace(new MonitorOptions([
      'threshold_ms' => 0, 'max_snapshot_bytes' => 262144, 'max_body_bytes' => 65536,
    ]), ['method' => 'GET', 'path' => '/budget']);
    for ($index = 0; $index < 200; $index++) {
      $slot = $trace->beginOperation(['type' => 'other', 'detail' => str_repeat('x', 5000)]);
      if ($slot !== null) { $trace->endOperation($slot, hrtime(true), ['success' => true]); }
    }
    $trace->updateResponse(['status' => 201, 'content_type' => 'application/json; padding=' . str_repeat('x', 60000)]);
    $snapshot = $trace->snapshot();
    self::assertLessThanOrEqual(262144, strlen(json_encode(
      $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    )));
    self::assertSame(201, $snapshot->response->status);
    self::assertTrue($snapshot->snapshotTruncated);
  }

  /** @return void 长展示值可以隐藏，JSON 与表单的解析类型必须保留。 */
  public function testHiddenLongContentTypePreservesBodyParser(): void
  {
    foreach ([
      ['application/problem+json', '{"password":"private","ok":true}', ['password' => '[REDACTED]', 'ok' => true]],
      ['application/x-www-form-urlencoded', 'password=private&ok=yes', ['password' => '[REDACTED]', 'ok' => 'yes']],
    ] as [$type, $body, $expected]) {
      $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), []);
      $trace->updateResponse(['content_type' => $type . '; padding=' . str_repeat('x', 60000)]);
      $trace->appendResponse($body, true);
      $snapshot = $trace->snapshot();
      self::assertSame($expected, $snapshot->response->body->content);
      self::assertLessThanOrEqual(256, strlen($snapshot->response->contentType));
      self::assertTrue($snapshot->response->completed);
    }
  }

  /** @return void 预算用尽时只保留固定信息，释放空间后正文仍能正常采集。 */
  public function testFailedMetadataReservationDoesNotDisableBodyCapture(): void
  {
    $options = new MonitorOptions(['max_snapshot_bytes' => 16384]);
    $budget = new SnapshotBudget($options->maxSnapshotBytes);
    $capture = new ResponseCapture($options, $budget, new PayloadSanitizer($options));
    self::assertTrue($budget->replace('other', 8192));
    $capture->updateResponse(['status' => 202, 'content_type' => 'application/json; padding=' . str_repeat('x', 60000)]);
    self::assertArrayNotHasKey('content_type', $capture->snapshot());
    $budget->release('other');
    $capture->appendResponse('{"ok":true}', true);
    $snapshot = $capture->snapshot();
    self::assertSame(['ok' => true], $snapshot['body']['content']);
    self::assertSame(202, $snapshot['status']);
    self::assertLessThanOrEqual(16384, SnapshotBudget::size($snapshot));
  }

  /** @return void 重新保留的短元数据必须占用共享配额，不能借用固定预留。 */
  public function testFallbackMetadataIsChargedAgain(): void
  {
    $options = new MonitorOptions(['max_snapshot_bytes' => 16384]);
    $budget = new SnapshotBudget($options->maxSnapshotBytes);
    $capture = new ResponseCapture($options, $budget, new PayloadSanitizer($options));
    self::assertTrue($budget->replace('other', 7000));
    $capture->updateResponse(['status' => 201, 'content_type' => 'application/json', 'extra' => str_repeat('x', 2000)]);
    // 完整元数据容纳不下，兜底约 70 字节；再申请 1150 字节应超出剩余配额。
    self::assertFalse($budget->replace('probe', 1150));
    self::assertSame('application/json', $capture->snapshot()['content_type']);
  }

  /** @return void 展示字段整体脱敏不应被解析器状态反向泄漏。 */
  public function testRedactedContentTypeStillUsesSafeBodyParser(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'sensitive_keys' => ['content_type']]), []);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->appendResponse('{"password":"private","ok":true}', true);
    $record = $trace->finish();
    self::assertSame('[REDACTED]', $record['response']['content_type']);
    self::assertSame(['password' => '[REDACTED]', 'ok' => true], $record['response']['body']['content']);
  }
}
