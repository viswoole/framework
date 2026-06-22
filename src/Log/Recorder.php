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

namespace Viswoole\Log;

use Stringable;
use Viswoole\Log\Contract\DriveInterface;

/**
 * 协程日志记录器，在协程生命周期内缓存日志并在析构时批量写入
 *
 * 每个协程持有独立的 Recorder 实例，析构时将缓存的日志通过驱动批量持久化，
 * 避免每条日志都触发 IO 操作。
 *
 * @see Drive 日志驱动基类
 */
class Recorder
{
  protected array $records = [];

  /**
   * @param DriveInterface $drive 关联的日志驱动，析构时调用其 save 方法
   */
  public function __construct(protected DriveInterface $drive)
  {

  }

  /**
   * 将一条日志追加到缓存队列
   *
   * @param string $level 日志级别
   * @param string|Stringable $message 日志消息
   * @param array $context 附加上下文信息
   */
  public function push(string $level, string|Stringable $message, array $context = []): void
  {
    $this->records[] = LogManager::createLogData($level, $message, $context);
  }

  /**
   * 获取当前缓存的所有日志记录
   *
   * @return array 日志记录数组
   */
  public function get(): array
  {
    return $this->records;
  }

  /**
   * 析构时将缓存的日志批量写入驱动并清空缓存
   */
  public function __destruct()
  {
    $this->drive->save($this->records);
    $this->clear();
  }

  /**
   * 清空已缓存的日志记录
   */
  public function clear(): void
  {
    $this->records = [];
  }
}
