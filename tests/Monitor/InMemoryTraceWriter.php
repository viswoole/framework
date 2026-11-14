<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use Viswoole\Monitor\Contract\TraceWriterInterface;

/**
 * 测试存储替身，避免单元测试依赖日志文件生命周期
 */
final class InMemoryTraceWriter implements TraceWriterInterface
{
  /** @var array<int,array<string,mixed>> 已写入的记录列表 */
  public array $records = [];
  /** @var list<SlowRequestSnapshot> 实际收到的类型化快照 */
  public array $snapshots = [];

  /**
   * 将记录追加到内存列表
   *
   * @param SlowRequestSnapshot $snapshot 已脱敏且定稿的不可变请求快照
   */
  public function write(SlowRequestSnapshot $snapshot): void
  {
    $this->records[] = $snapshot->toArray();
    $this->snapshots[] = $snapshot;
  }
}
