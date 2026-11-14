<?php

/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Monitor\Writer;

use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use Viswoole\Log\Drives\File;
use Viswoole\Monitor\Contract\TraceWriterInterface;

/**
 * 基于框架文件日志的慢请求 JSON 存储实现
 *
 * 职责：将慢请求记录写入独立的日志目录。
 * 边界：复用框架文件日志的 JSON 编码、按日分目录、轮转与过期清理机制；
 * 不负责脱敏（收到的记录已完成脱敏与限制）。
 */
final readonly class JsonTraceWriter implements TraceWriterInterface
{
  /**
   * 构造写入器
   *
   * @param File $driver 框架文件日志驱动（JSON 模式）
   */
  public function __construct(private File $driver) {}

  /**
   * 写入一条慢请求记录
   *
   * 直接写入而非走协程缓冲，避免依赖协程析构时机；
   * 慢请求在 HTTP 入口 finally 中已采集完整。
   *
   * @param SlowRequestSnapshot $snapshot 已脱敏且定稿的不可变请求快照
   */
  public function write(SlowRequestSnapshot $snapshot): void
  {
    $this->driver->write('slow_request', 'Slow HTTP request', $snapshot->toArray());
  }
}
