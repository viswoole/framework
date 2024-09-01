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

namespace Viswoole\Database\Collection;

use ArrayObject;

/**
 * 数据行，键为字段名，值为字段值，支持快捷更新
 *
 * 继承ArrayObject，支持数组、属性方式的读取和写入
 *
 * 实现了JsonSerializable接口，可以安全的转换为json
 */
class Row extends BaseCollection
{
  protected int $flags = ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS;

  /**
   * 获取某一列的值
   *
   * @param string $column
   * @return mixed
   */
  public function value(string $column): mixed
  {
    return $this->offsetGet($column);
  }
}
