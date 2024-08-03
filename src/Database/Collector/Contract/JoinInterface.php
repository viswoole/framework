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

namespace Viswoole\Database\Collector\Contract;

use Viswoole\Database\Collector\Raw;

interface JoinInterface
{
  /**
   * left关联查询
   *
   * @access public
   * @param string|Raw $table 要关联的表,以及别名
   * @param string $condition 连接条件，例如 a.id = b.id,注意运算符两侧需要使用空格隔开
   * @return static
   */
  public function leftJoin(string|Raw $table, string $condition): static;

  /**
   * right关联查询
   *
   * @access public
   * @param string|Raw $table 要关联的表,以及别名
   * @param string $condition 连接条件，例如 a.id = b.id,注意运算符两侧需要使用空格隔开
   * @return static
   */
  public function rightJoin(string|Raw $table, string $condition): static;

  /**
   * FULL关联查询
   *
   * @access public
   * @param string|Raw $table 要关联的表,以及别名
   * @param string $condition 连接条件，例如 a.id = b.id,注意运算符两侧需要使用空格隔开
   * @return static
   */
  public function fullJoin(string|Raw $table, string $condition): static;

  /**
   * 关联查询
   *
   * @access public
   * @param string|Raw $table 要关联的表,以及别名
   * @param string $condition 连接条件，例如 a.id = b.id,注意运算符两侧需要使用空格隔开
   * @param string $type ['INNER', 'LEFT', 'RIGHT', 'FULL']，不区分大小写
   * @return static
   */
  public function join(
    string|Raw $table,
    string     $condition,
    string     $type = 'INNER'
  ): static;
}
