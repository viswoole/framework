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
 * 分页查询
 */
trait PageTrait
{
  /**
   * 限制查询数量
   *
   * @access public
   * @param int $limit 要查询的数量
   * @return static
   */
  public function limit(int $limit): static
  {
    $this->options->limit = $limit;
    return $this;
  }

  /**
   * 偏移量
   *
   * @param int $offset 偏移量
   * @return static
   */
  public function offset(int $offset): static
  {
    $this->options->offset = $offset;
    return $this;
  }

  /**
   * 分页查询
   *
   * @access public
   * @param int $page 查询第几页
   * @param int $limit 查询多少条数据
   * @return static
   */
  public function page(int $page = 1, int $limit = 10): static
  {
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;
    $this->options->offset = $offset;
    $this->options->limit = $limit;
    return $this;
  }
}
