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

namespace Viswoole\Core\Server\Shutdown;

/**
 * 进程退出与任务处理结果分开表达；没有运行期回执时不宣称已正常排空
 */
enum StopResult: string
{
  /**
   * 优雅退出，在途任务和请求均已结束
   */
  case Graceful = 'graceful';
  /**
   * 超时强停或存在被中断的任务
   */
  case Forced = 'forced';
  /**
   * 进程已退出但缺少可信回执
   */
  case Unconfirmed = 'unconfirmed';
  /**
   * 停止失败，退出状态未确认
   */
  case Failed = 'failed';

  /**
   * 生成面向命令调用方的结果消息
   *
   * @param string $name 服务名称
   * @return string 面向命令调用方的结果
   */
  public function message(string $name): string
  {
    return match ($this) {
      self::Graceful => "✅ {$name}服务已正常停止，已进入处理器的任务和请求均已结束（不代表业务成功）",
      self::Forced => "⚠️ {$name}服务已超时强停或存在被中断的任务，不能确认任务全部完成",
      self::Unconfirmed => "⚠️ {$name}服务进程已退出，但缺少停机回执，无法确认在途任务全部完成",
      self::Failed => "❌ {$name}服务停止失败，退出状态未能确认，PID 文件已保留",
    };
  }
}
