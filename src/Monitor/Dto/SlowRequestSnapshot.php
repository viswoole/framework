<?php

declare(strict_types=1);

/* 职责：定义交给存储方的慢请求定稿契约，兼容历史字段并增加版本 2 的采集来源。 */

namespace Viswoole\Monitor\Dto;

use Viswoole\Monitor\OperationType;

/**
 * 慢请求不可变快照
 *
 * 仅慢请求收尾时构造，操作及嵌套记录均使用 readonly DTO。
 * toArray/JSON 保留字段是否存在及脱敏标记，不重排操作明细。
 */
final readonly class SlowRequestSnapshot extends SnapshotRecord
{
  public string $requestId;
  public string $timestamp;
  public float $durationMs;
  public ?float $responseTimeMs;
  public RequestSnapshot $request;
  public ResponseSnapshot $response;
  /** @var list<DatabaseOperationSnapshot|CacheOperationSnapshot|OtherOperationSnapshot> */
  public array $operations;
  /** @var array<string,OperationStatistics> */
  public array $operationSummary;
  public int $droppedOperations;
  public ?string $exception;
  public int $schemaVersion;
  public bool $snapshotTruncated;
  public ?CaptureSnapshot $capture;

  /**
   * 创建已完成的慢请求快照
   *
   * @param array<string,mixed> $record 采集器完成脱敏及限额处理的定稿记录
   * @param array<int,OperationType> $operationTypes 不受展示脱敏影响的内部分类
   */
  public function __construct(array $record, array $operationTypes = [])
  {
    parent::__construct($record);
    $safe = $this->toArray();
    $this->requestId = $safe['request_id'];
    $this->timestamp = $safe['timestamp'];
    $this->durationMs = (float)$safe['duration_ms'];
    $this->responseTimeMs = isset($safe['response_time_ms']) ? (float)$safe['response_time_ms'] : null;
    $this->request = new RequestSnapshot($safe['request']);
    $this->response = new ResponseSnapshot($safe['response']);
    $this->operations = self::createOperations($safe['operations'], $operationTypes);
    $this->operationSummary = array_map(
      static fn (array $summary): OperationStatistics => new OperationStatistics($summary),
      $safe['operation_summary']
    );
    $this->droppedOperations = $safe['dropped_operations'];
    $this->exception = $safe['exception'];
    $this->schemaVersion = $safe['schema_version'];
    $this->snapshotTruncated = $safe['snapshot_truncated'];
    $this->capture = isset($safe['capture']) ? new CaptureSnapshot($safe['capture']) : null;
  }

  /**
   * 按可信分类创建操作 DTO
   *
   * @param list<array<string,mixed>> $operations 定稿明细
   * @param array<int,OperationType> $types 采集器保存的内部分类
   * @return list<DatabaseOperationSnapshot|CacheOperationSnapshot|OtherOperationSnapshot> 类型化操作
   */
  private static function createOperations(array $operations, array $types): array
  {
    $result = [];
    foreach ($operations as $index => $operation) {
      $type = $types[$index] ?? OperationType::resolve($operation['type'] ?? null);
      $result[] = match ($type) {
        OperationType::DATABASE => new DatabaseOperationSnapshot($operation),
        OperationType::CACHE => new CacheOperationSnapshot($operation),
        OperationType::OTHER => new OtherOperationSnapshot($operation),
      };
    }
    return $result;
  }
}
