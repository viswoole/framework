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

/**
 * 分组查询
 */
trait GroupTrait
{
  /**
   * 分组
   *
   * @access public
   * @param string|array $columns
   * @return static
   */
  public function group(array|string $columns): static
  {
    if (is_string($columns)) $columns = explode(',', $columns);
    $this->options->group = $columns;
    return $this;
  }

  /**
   * 用于配合group方法完成从分组的结果中筛选
   *
   * @access public
   * @param string $condition
   * @return static
   */
  public function having(string $condition): static
  {
    $this->options->having = $condition;
    return $this;
  }
}
