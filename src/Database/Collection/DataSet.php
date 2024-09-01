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
use Override;
use RuntimeException;

/**
 * 数据行，键为字段名，值为字段值，支持快捷更新
 *
 * 继承ArrayObject，支持数组、属性方式的读取和写入
 *
 * 实现了JsonSerializable接口，可以安全的转换为json
 */
class DataSet extends BaseCollection
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

  /**
   * 删除集合中的所有记录
   *
   * @param bool $real 是否为硬删除，默认为false，仅模型查询结果支持$real参数。
   * @return int 成功返回1，失败返回0
   * @throws RuntimeException 如果缺少主键字段
   */
  #[Override] public function delete(bool $real = false): int
  {
    $pk = $this->query->getOptions()->pk;
    if (isset($this[$pk])) {
      return $this->query->where($pk, $this[$pk])->delete($real);
    } else {
      throw new RuntimeException(
        "快捷删除记录失败，缺少主键字段($pk)"
      );
    }
  }

  /**
   * 合并数据，相同的字段将被新数据覆盖
   *
   * @param array $data
   * @return static
   */
  public function merge(array $data): static
  {
    foreach ($data as $key => $value) {
      $this[$key] = $value;
    }
    return $this;
  }
}
