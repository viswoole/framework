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

declare (strict_types=1);

namespace Viswoole\Log\Contract;

use Stringable;

/**
 * 日志驱动接口，定义日志的缓存、读取与持久化契约
 *
 * 继承 CollectorInterface 以获得各级别快捷方法，额外定义记录缓存管理
 * 和持久化写入能力，供 Drive 抽象类实现。
 *
 * @see CollectorInterface 收集器接口
 */
interface DriveInterface extends CollectorInterface
{
  /**
   * 清空当前协程缓存的日志记录
   */
  public function clearRecord(): void;

  /**
   * 获取当前协程缓存的日志记录
   *
   * @return array 缓存的日志记录列表
   */
  public function getRecord(): array;

  /**
   * 批量持久化日志记录（协程结束时由 Recorder 自动调用）
   *
   * @param array<int,array{timestamp:int,level:string,message:string,context:array,source:string}> $logRecords 待持久化的日志记录列表
   */
  public function save(array $logRecords): void;

  /**
   * 缓存一条日志记录，协程环境下延迟到协程结束时批量写入
   *
   * @param string $level 日志级别
   * @param Stringable|string $message 日志消息
   * @param array $context 附加上下文信息
   */
  public function record(
    string            $level,
    Stringable|string $message,
    array             $context = [],
  ): void;

  /**
   * 绕过缓存立即写入一条日志
   *
   * @param string $level 日志级别
   * @param Stringable|string $message 日志消息
   * @param array $context 附加上下文信息
   */
  public function write(
    string            $level,
    Stringable|string $message,
    array             $context = [],
  ): void;
}
