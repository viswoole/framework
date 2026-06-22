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

namespace Viswoole\Core\Exception;

use Throwable;
use Viswoole\Log\LogManager;

/**
 * 全局异常处理器，负责异常的日志记录与渲染输出
 */
class Handle
{
  /**
   * @var array<string> 不写入日志的异常类名列表
   */
  protected array $ignoreReport = [
    ValidateException::class
  ];

  /**
   * @param LogManager $log 日志管理器，用于记录异常日志
   */
  public function __construct(protected readonly LogManager $log)
  {
  }

  /**
   * 渲染异常，触发日志记录流程
   *
   * @param Throwable $e 待处理的异常实例
   */
  public function render(Throwable $e): void
  {
    $this->report($e);
  }

  /**
   * 将异常信息写入日志，忽略列表中的异常类型不记录
   *
   * @param Throwable $e 待记录的异常实例
   */
  public function report(Throwable $e): void
  {
    if (!$this->isIgnoreReport($e)) {
      $data = [
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
      ];
      // 记录异常到日志
      $this->log->error($e->getMessage(), $data);
    }
  }

  /**
   * 判断异常是否在忽略日志列表中
   *
   * @param Throwable $exception 待检查的异常实例
   * @return bool 在忽略列表中返回 true，否则返回 false
   */
  protected function isIgnoreReport(Throwable $exception): bool
  {
    foreach ($this->ignoreReport as $class) {
      if ($exception instanceof $class) return true;
    }
    return false;
  }
}
