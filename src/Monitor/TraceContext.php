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

namespace Viswoole\Monitor;

use Throwable;
use Viswoole\Core\Coroutine\Context;

/**
 * 协程采集入口
 *
 * 职责：以静态门面形式向数据库、缓存等采集点暴露当前请求的采集状态，
 * 并为单次操作提供计时包装。
 * 边界：不负责日志持久化（由 SlowRequestMonitor 与 TraceWriterInterface
 * 承担）；无请求上下文时直接调用原操作，不分配操作明细。
 */
final class TraceContext
{
  /** @var string 当前协程上下文中存放请求采集状态的键名 */
  private const string KEY = '__viswoole_request_trace';

  /**
   * 将请求采集状态挂载到当前协程上下文
   *
   * @param RequestTrace $trace 当前请求状态
   */
  public static function attach(RequestTrace $trace): void
  {
    Context::set(self::KEY, $trace);
  }

  /**
   * 获取当前协程的请求采集状态
   *
   * @return RequestTrace|null 当前协程请求，未启用采集时为 null
   */
  public static function current(): ?RequestTrace
  {
    return Context::get(self::KEY);
  }

  /**
   * 获取当前请求关联 ID
   *
   * 不向业务暴露可变采集对象；未采集或当前请求上下文已清理时返回 null。
   *
   * @return string|null 与快照和响应头一致的请求 ID
   */
  public static function requestId(): ?string
  {
    return self::current()?->requestId;
  }

  /**
   * 清理当前协程的请求采集状态
   *
   * 请求结束时主动清理，兼容非协程执行。
   */
  public static function clear(): void
  {
    Context::remove(self::KEY);
  }

  /**
   * 计时并执行原始操作
   *
   * 保持原操作的返回值和异常语义：成功记录结果摘要，失败记录异常类型后
   * 原样抛出。未启用采集时直接执行；明细超限后仍累加耗时与失败统计。
   *
   * @param array $metadata 操作元数据（类型、SQL、缓存键等）
   * @param callable():mixed $operation 原始操作
   * @return mixed 原操作返回值
   * @throws Throwable 原操作抛出的异常原样向上传播
   */
  public static function measure(array $metadata, callable $operation): mixed
  {
    $trace = self::current();
    if ($trace === null) { return $operation(); }
    if (($metadata['type'] ?? '') === 'database' && !$trace->options->captureDatabase) { return $operation(); }
    if (($metadata['type'] ?? '') === 'cache' && !$trace->options->captureCache) { return $operation(); }
    try {
      $index = $trace->beginOperation($metadata);
    } catch (Throwable $error) {
      trigger_error('慢请求操作采集失败: ' . $error::class, E_USER_WARNING);
      return $operation();
    }
    $startedAt = hrtime(true);
    try {
      $result = $operation();
    } catch (Throwable $error) {
      self::complete($trace, ['index' => $index, 'started_at' => $startedAt, 'metadata' => $metadata,
        'outcome' => ['success' => false, 'exception' => $error::class]]);
      throw $error;
    }
    self::complete($trace, ['index' => $index, 'started_at' => $startedAt, 'metadata' => $metadata,
      'outcome' => OperationOutcome::summarize($metadata, $result)]);
    return $result;
  }

  /**
   * 安全记录操作结束
   *
   * 收尾错误不能把成功的写操作伪装成业务失败；超限时只保留固定汇总。
   *
   * @param RequestTrace $trace 当前采集状态
   * @param array{index:int|null,started_at:int,metadata:array,outcome:array} $operation 操作信息
   * @return void
   */
  private static function complete(RequestTrace $trace, array $operation): void
  {
    try {
      if ($operation['index'] !== null) {
        $trace->endOperation($operation['index'], $operation['started_at'], $operation['outcome']);
        return;
      }
      $trace->recordDroppedOperation([
        'type' => $operation['metadata']['type'] ?? 'other',
        'duration_ms' => (hrtime(true) - $operation['started_at']) / 1e6,
        'success' => $operation['outcome']['success'],
      ]);
    } catch (Throwable $error) {
      trigger_error('慢请求操作收尾失败: ' . $error::class, E_USER_WARNING);
    }
  }
}
