<?php

declare(strict_types=1);

/* 职责：验证请求属性固定类型、采集状态分离及旧 JSON 字段兼容。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\Dto\BodySnapshot;
use Viswoole\Monitor\Dto\CaptureState;
use Viswoole\Monitor\Dto\RequestSnapshot;

/** 明确 DTO 数据与采集状态契约。 */
final class ExplicitSnapshotTypesTest extends TestCase
{
  /**
   * 请求头和参数保持固定数据类型
   *
   * @return void
   */
  public function testCapturedAndRedactedFieldsHaveStableTypes(): void
  {
    $input = ['method' => 'POST', 'path' => '/test', 'headers' => '[REDACTED]',
      'query' => ['page' => 1, '__truncated' => true], 'body' => '[REDACTED]'];
    $snapshot = new RequestSnapshot($input);
    self::assertSame('POST', $snapshot->method);
    self::assertSame('/test', $snapshot->path);
    self::assertSame([], $snapshot->headers->values);
    self::assertTrue($snapshot->headers->isRedacted);
    self::assertSame(CaptureState::REDACTED, $snapshot->headers->state);
    self::assertSame(['page' => 1], $snapshot->query->values);
    self::assertTrue($snapshot->query->isTruncated);
    self::assertNull($snapshot->body->content);
    self::assertTrue($snapshot->body->isRedacted);
    self::assertFalse($snapshot->body->isSizeKnown);
    self::assertSame($input, $snapshot->toArray());
  }

  /**
   * 未采集与空集合区分
   *
   * @return void
   */
  public function testMissingAndEmptyCollectionsAreDistinct(): void
  {
    $missing = new RequestSnapshot([]);
    $empty = new RequestSnapshot(['headers' => [], 'query' => []]);
    self::assertIsString($missing->method);
    self::assertSame('', $missing->method);
    self::assertSame(CaptureState::NOT_CAPTURED, $missing->headers->state);
    self::assertSame(CaptureState::CAPTURED, $empty->headers->state);
    self::assertSame([], $missing->headers->values);
    self::assertSame([], $empty->headers->values);
    self::assertSame(CaptureState::NOT_CAPTURED, $missing->routeState);
    self::assertNull($missing->route);
  }

  /**
   * 正文保留合法零值及固定元数据
   *
   * @return void
   */
  public function testBodyScalarContentAndCaptureStates(): void
  {
    foreach ([0, false, null, '', '[ordinary text]', ['ok' => true]] as $content) {
      $body = new BodySnapshot(['content' => $content, 'bytes' => 4, 'truncated' => false]);
      self::assertSame($content, $body->content);
      self::assertSame(4, $body->bytes);
      self::assertTrue($body->isSizeKnown);
      self::assertSame(CaptureState::CAPTURED, $body->state);
    }
    $limited = new BodySnapshot(['content' => '[BODY_LIMIT]', 'bytes' => 100, 'truncated' => true]);
    self::assertTrue($limited->isTruncated);
    self::assertNull($limited->content);
  }

  /**
   * 业务内容包含占位词片段不被子串误判，截断状态按显式标记解析
   */
  public function testBusinessStringContainingPlaceholderFragmentStaysCaptured(): void
  {
    $body = new BodySnapshot(['content' => 'RATE LIMIT exceeded', 'bytes' => 19, 'truncated' => false]);
    self::assertSame(CaptureState::CAPTURED, $body->state);
    self::assertSame('RATE LIMIT exceeded', $body->content);
    $truncated = new BodySnapshot(['content' => ['name' => 'x', '__truncated' => true], 'bytes' => 10, 'truncated' => false]);
    self::assertTrue($truncated->isTruncated);
    self::assertNull($truncated->content);
  }
}
