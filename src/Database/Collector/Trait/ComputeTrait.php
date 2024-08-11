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

namespace Viswoole\Database\Collector\Trait;

use Viswoole\Database\Collector\Raw;

/**
 * 设置某个字段自增或自减
 */
trait ComputeTrait
{

  /**
   * 自减
   *
   * @param string|Raw $column
   * @param float|int $step
   * @return static
   */
  public function dec(
    string|Raw $column,
    float|int  $step = 1,
  ): static
  {
    $this->options->autoDec[] = ['column' => $column, 'step' => $step];
    return $this;
  }

  /**
   * 自增
   *
   * @param string|Raw $column
   * @param float|int $step
   * @return static
   */
  public function inc(
    string|Raw $column,
    float|int  $step = 1,
  ): static
  {
    $this->options->autoInc[] = ['column' => $column, 'step' => $step];
    return $this;
  }
}
