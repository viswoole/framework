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

interface DriveInterface extends CollectorInterface
{
  /**
   * 清除日志
   *
   * @return void
   */
  public function clearRecord(): void;

  /**
   * 获取缓存日志
   *
   * @return array
   */
  public function getRecord(): array;

  /**
   * 保存日志(协程结束，日志记录器销毁时会自动调用该方法存储日志)
   *
   * @param array<int,array{timestamp:int,level:string,message:string,context:array,source:string}> $logRecords 需要写入日志的记录
   * @return void
   */
  public function save(array $logRecords): void;

  /**
   * 记录日志缓存
   *
   * @param string $level 日志等级
   * @param Stringable|string $message 日志消息
   * @param array $context 日志附加信息
   * @return void
   */
  public function record(
    string            $level,
    Stringable|string $message,
    array             $context = [],
  ): void;

  /**
   * 实时写入日志
   *
   * @param string $level 日志等级
   * @param Stringable|string $message 日志消息
   * @param array $context 日志附加信息
   * @return void
   */
  public function write(
    string            $level,
    Stringable|string $message,
    array             $context = [],
  ): void;
}
