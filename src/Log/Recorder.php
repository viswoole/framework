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

class Recorder
{
  protected array $records = [];

  public function __construct(protected DriveInterface $drive)
  {

  }

  /**
   * 添加日志到记录器
   *
   * @param string $level 日志等级
   * @param string|Stringable $message 日志内容
   * @param array $context 日志上下文
   * @return void
   */
  public function push(string $level, string|Stringable $message, array $context = []): void
  {
    $this->records[] = LogManager::createLogData($level, $message, $context);
  }

  /**
   * 获取缓存的日志数据
   *
   * @return array
   */
  public function get(): array
  {
    return $this->records;
  }

  /**
   * 销毁时保存日志
   */
  public function __destruct()
  {
    $this->drive->save($this->records);
    $this->clear();
  }

  /**
   * 清除缓存的日志
   *
   * @return void
   */
  public function clear(): void
  {
    $this->records = [];
  }
}
