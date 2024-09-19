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
 * 异常处理基类
 */
class Handle
{
  /**
   * @var array 忽略的异常
   */
  protected array $ignoreReport = [
    ValidateException::class
  ];

  /**
   * @param LogManager $log
   */
  public function __construct(protected readonly LogManager $log)
  {
  }

  /**
   * 处理异常
   *
   * @param Throwable $e
   * @return void
   */
  public function render(Throwable $e): void
  {
    $this->report($e);
  }

  /**
   * 写入日志
   *
   * @param Throwable $e
   * @return void
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
   * 判断是否被忽视不写入日志
   * @param Throwable $exception
   * @return bool
   */
  protected function isIgnoreReport(Throwable $exception): bool
  {
    foreach ($this->ignoreReport as $class) {
      if ($exception instanceof $class) return true;
    }
    return false;
  }
}
