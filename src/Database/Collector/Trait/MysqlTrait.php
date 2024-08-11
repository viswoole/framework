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
 * mysql可用的方法
 */
trait MysqlTrait
{
  /**
   * 设置replace写入(仅mysql可用)
   * 如果数据存在则删除数据再写入 等同于强制写入
   *
   * @return static
   */
  public function replace(): static
  {
    $this->options->replace = true;
    return $this;
  }

  /**
   * 分区查询（仅mysql可用）
   * 如果你想查看表的分区信息，可以使用 SHOW TABLE STATUS 命令 ：
   * SHOW TABLE STATUS LIKE 'table_name'
   *
   * @access public
   * @param string|array $partition
   * @return static
   */
  public function partition(array|string $partition): static
  {
    if (is_string($partition)) $partition = [$partition];
    $this->options->partition = $partition;
    return $this;
  }
}
