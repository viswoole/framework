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
use Viswoole\Database\Exception\DbException;

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
   * @var array 修改过的字段
   */
  protected array $change = [];

  /**
   * 删除集合中的所有记录
   *
   * @param bool $real 是否为硬删除，默认为false，仅模型查询结果支持$real参数。
   * @return int 成功返回1，失败返回0
   * @throws RuntimeException 如果缺少主键字段
   * @throws DbException
   * @throws DbException
   */
  #[Override] public function delete(bool $real = false): int
  {
    $pk = $this->query->getPrimaryKey();
    if (isset($this[$pk])) {
      return $this->query->where($pk, $this[$pk])->delete($real);
    } else {
      throw new RuntimeException(
        "快捷删除记录失败，缺少主键字段($pk)"
      );
    }
  }

  /**
   * @inheritDoc
   */
  public function offsetSet(mixed $key, mixed $value): void
  {
    if ($value !== $this->offsetGet($key)) {
      $this->change[] = $key;
    }
    parent::offsetSet($key, $value);
  }

  /**
   * 合并数据，相同的字段将被新数据覆盖，如果需要保存数据则接着调用一次save方法即可
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

  /**
   * 保存数据到数据库
   *
   * @return bool 保存成功返回true，失败返回false，无数据更新也会返回false
   * @throws DbException
   * @throws DbException
   * @throws DbException
   */
  public function save(): bool
  {
    $pk = $this->query->getPrimaryKey();
    if (isset($this[$pk])) {
      $change = [];
      foreach ($this->change as $key) {
        // 如果字段不存在，则跳过
        if (!$this->offsetExists($key)) continue;
        $value = $this[$key];
        // 如果值是集合，则跳过
        if ($value instanceof BaseCollection) continue;
        $change[$key] = $value;
      }
      if (empty($change)) {
        $changeCount = 0;
      } else {
        $changeCount = $this->query->strict(false)->where($pk, $this[$pk])->update($change);
      }
      $this->change = [];
      return $changeCount === 1;
    } else {
      throw new RuntimeException("保存数据失败，缺少主键字段($pk)");
    }
  }

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
