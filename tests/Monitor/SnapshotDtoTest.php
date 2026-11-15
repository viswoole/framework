<?php

declare(strict_types=1);

/* 职责：验证存储 DTO 类型、JSON 兼容、脱敏联合类型与深层不可变性。 */

namespace Viswoole\Tests\Monitor;

use Error;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\Dto\CacheOperationSnapshot;
use Viswoole\Monitor\Dto\DatabaseOperationSnapshot;
use Viswoole\Monitor\Dto\OtherOperationSnapshot;
use Viswoole\Monitor\Dto\RequestSnapshot;
use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\OperationType;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;

/**
 * 存储快照 DTO 契约
 *
 * 内部数组收集方式不泄漏到存储接口，转换前后必须保留同一套 JSON 字段。
 */
final class SnapshotDtoTest extends TestCase
{
  /**
   * 类型化属性与数组字段一致
   *
   * @return void
   */
  public function testTypedSnapshotPreservesJsonContract(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'record_sql' => true]), [
      'method' => 'POST', 'path' => '/test', 'query' => ['page' => 1],
    ]);
    $trace->updateRoute(['paths' => ['/test'], 'parameters' => ['id' => '1'], 'handler' => 'Controller::test']);
    TraceContext::attach($trace);
    try {
      TraceContext::measure(['type' => 'database', 'sql' => 'SELECT 1', 'channel' => 'default'], static fn (): bool => true);
      TraceContext::measure(['type' => 'cache', 'operation' => 'get', 'store' => 'file'], static fn (): bool => false);
      TraceContext::measure(['type' => 'custom'], static fn (): bool => true);
    } finally {
      TraceContext::clear();
    }
    $trace->updateResponse(['status' => 201, 'content_type' => 'application/json']);
    $trace->appendResponse('{"ok":true}', true);
    $finishedAt = $trace->startedAt + 100000000;
    $snapshot = $trace->snapshot($finishedAt);
    self::assertInstanceOf(SlowRequestSnapshot::class, $snapshot);
    self::assertSame($trace->finish($finishedAt), $snapshot->toArray());
    self::assertSame($snapshot->toArray(), json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true));
    self::assertSame($trace->requestId, $snapshot->requestId);
    self::assertSame('/test', $snapshot->request->path);
    self::assertSame(['/test'], $snapshot->request->route->paths);
    self::assertSame(201, $snapshot->response->status);
    self::assertInstanceOf(DatabaseOperationSnapshot::class, $snapshot->operations[0]);
    self::assertSame('SELECT 1', $snapshot->operations[0]->sql);
    self::assertInstanceOf(CacheOperationSnapshot::class, $snapshot->operations[1]);
    self::assertInstanceOf(OtherOperationSnapshot::class, $snapshot->operations[2]);
    self::assertSame(1, $snapshot->operationSummary['database']->count);
  }

  /**
   * 脱敏不会改变真实操作类型
   *
   * @return void
   */
  public function testRedactedTypeStillProducesDatabaseDto(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'privacy' => PrivacyOptions::disableBodies(['type', 'status'])]), []);
    TraceContext::attach($trace);
    try {
      TraceContext::measure(['type' => 'database', 'sql' => 'SELECT 1'], static fn (): bool => true);
      $trace->updateResponse(['status' => 500]);
      $trace->appendResponse('{"ok":true}', true);
      $snapshot = $trace->snapshot();
      self::assertInstanceOf(DatabaseOperationSnapshot::class, $snapshot->operations[0]);
      self::assertSame(OperationType::DATABASE, $snapshot->operations[0]->kind);
      self::assertSame('[REDACTED]', $snapshot->operations[0]->type);
      self::assertSame(0, $snapshot->response->status);
      self::assertSame(\Viswoole\Monitor\Dto\CaptureState::REDACTED, $snapshot->response->fieldStates['status']);
      self::assertSame(\Viswoole\Monitor\Dto\CaptureState::NOT_CAPTURED, $snapshot->response->body->state);
      self::assertNull($snapshot->response->body->content);
    } finally {
      TraceContext::clear();
    }
  }

  /**
   * 数据引用与属性不可修改
   *
   * @return void
   */
  public function testNestedReferencesAreDetached(): void
  {
    $name = 'before';
    $input = ['query' => ['name' => &$name]];
    $snapshot = new RequestSnapshot($input);
    $name = 'after';
    self::assertSame('before', $snapshot->query->values['name']);
    $output = $snapshot->toArray();
    $output['query']['name'] = 'mutated';
    self::assertSame('before', $snapshot->toArray()['query']['name']);
    $this->expectException(Error::class);
    $snapshot->query->values['name'] = 'mutated';
  }

  /**
   * 禁止嵌套可变对象
   *
   * @return void
   */
  public function testMutableObjectsAreRejected(): void
  {
    $this->expectException(InvalidArgumentException::class);
    new RequestSnapshot(['body' => ['object' => new \stdClass()]]);
  }

  /**
   * 真正存储调用收到 DTO 且已清理上下文
   *
   * @return void
   */
  public function testWriterReceivesTypedSnapshot(): void
  {
    $writer = new InMemoryTraceWriter();
    $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]), $writer);
    $monitor->start('/dto', static fn (): array => []);
    $monitor->finish();
    self::assertNull(TraceContext::current());
    self::assertInstanceOf(SlowRequestSnapshot::class, $writer->snapshots[0]);
    self::assertSame($writer->records[0], $writer->snapshots[0]->toArray());
  }

  /**
   * 快请求不构造 DTO
   *
   * @return void
   */
  public function testFastRequestReturnsNull(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 1000]), []);
    self::assertNull($trace->snapshot($trace->startedAt + 1));
  }

  /**
   * 后续采集不能修改已生成的 DTO
   *
   * @return void
   */
  public function testDtoIsDetachedFromMutableTrace(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0]), ['path' => '/before']);
    $snapshot = $trace->snapshot($trace->startedAt + 1000000);
    $before = $snapshot->toArray();
    $trace->updateResponse(['status' => 503]);
    $trace->appendResponse('changed', true);
    $trace->updateRoute(['paths' => ['/later']]);
    self::assertSame($before, $snapshot->toArray());
    self::assertSame(200, $snapshot->response->status);
  }

  /**
   * DTO 不增加预算外的 JSON 字段
   *
   * @return void
   */
  public function testDtoSerializationRespectsSnapshotBudget(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'max_snapshot_bytes' => 16384]), []);
    TraceContext::attach($trace);
    try {
      for ($index = 0; $index < 200; $index++) {
        TraceContext::measure(['type' => 'database', 'sql' => str_repeat('x', 4000)], static fn (): bool => true);
      }
      $snapshot = $trace->snapshot();
      self::assertTrue($snapshot->snapshotTruncated);
      self::assertSame(200, $snapshot->operationSummary['database']->count);
      self::assertLessThanOrEqual(16384, strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    } finally {
      TraceContext::clear();
    }
  }

}
