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
use Viswoole\Monitor\Contract\TraceWriterInterface;

/**
 * 慢请求监控器，管理请求采集生命周期
 *
 * 职责：请求开始时挂载采集状态，请求结束时清理上下文并将达到阈值的
 * 记录交给存储层。
 * 边界：采集失败通过错误日志报告，不影响业务响应；先清理上下文再
 * 持久化，避免日志存储内部的操作被递归采集。
 */
final readonly class SlowRequestMonitor
{
  /**
   * 构造监控器
   *
   * @param MonitorOptions $options 采集配置
   * @param TraceWriterInterface $writer 记录存储实现
   */
  public function __construct(private MonitorOptions $options, private TraceWriterInterface $writer) {}

  /**
   * 开始一次请求采集
   *
   * @param string $path 请求路径，用于排除规则匹配
   * @param (callable():array)|(callable(PayloadSanitizer):array) $snapshot 延迟采集的请求快照
   * @param bool $canReceiveSanitizer 显式允许向快照回调传入共享脱敏器，默认保留无参调用
   */
  public function start(string $path, callable $snapshot, bool $canReceiveSanitizer = false): void
  {
    // 先清理残留上下文，防止上一请求异常退出导致的污染
    TraceContext::clear();
    try {
      if (!$this->options->isEnabled) { return; }
      // 路径只归一化一次，排除判断、敏感字段解析与脱敏器共用同一匹配器
      $matcher = new PathPolicyMatcher($path, $this->options->isRouteCaseSensitive);
      if (!$this->options->shouldCollect($path, $matcher)) { return; }
      $sanitizer = new PayloadSanitizer($this->options, $path, $matcher);
      $startedAt = hrtime(true);
      // 旧回调可能是严格限制参数个数的内置 callable，不能隐式增加入参。
      $request = $canReceiveSanitizer ? $snapshot($sanitizer) : $snapshot();
      TraceContext::attach(new RequestTrace(
        $this->options,
        array_replace($request, ['path' => $path]),
        $startedAt,
        $sanitizer
      ));
    } catch (Throwable $error) {
      $this->reportFailure($error);
    }
  }

  /**
   * 结束采集并持久化达到阈值的记录
   *
   * 先清理上下文再持久化，避免日志存储产生递归采集；可安全重复调用。
   */
  public function finish(): void
  {
    $trace = TraceContext::current();
    TraceContext::clear();
    if ($trace === null) { return; }
    try {
      $snapshot = $trace->snapshot();
      $trace->close();
      if ($snapshot !== null) { $this->writer->write($snapshot); }
    } catch (Throwable $error) {
      $this->reportFailure($error);
    } finally {
      $trace->close();
    }
  }

  /**
   * 报告监控内部失败
   *
   * @param Throwable $error 监控内部错误
   */
  private function reportFailure(Throwable $error): void
  {
    trigger_error('慢请求监控失败: ' . $error::class, E_USER_WARNING);
  }
}
