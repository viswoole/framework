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
 * 单行数据集，键为字段名，值为字段值
 *
 * 继承 BaseCollection，支持数组、属性方式的读写，自动追踪字段变更，
 * 可通过 save() 方法将变更持久化到数据库。
 *
 * @see BaseCollection
 */
class DataSet extends BaseCollection
{
  protected int $flags = ArrayObject::STD_PROP_LIST | ArrayObject::ARRAY_AS_PROPS;
  /** @var array 已修改的字段名列表，用于 save() 时增量更新 */
  protected array $change = [];
  /** @var array 最近一次同步数据库时的数据快照，用于判断字段是否改回原值 */
  protected array $original = [];

  /**
   * 构建单行数据集，保存原始数据快照用于变更追踪
   *
   * @param \Viswoole\Database\BaseQuery|\Viswoole\Database\Model\Query $query 查询对象
   * @param array $data 行数据
   */
  public function __construct(\Viswoole\Database\BaseQuery|\Viswoole\Database\Model\Query $query, array $data)
  {
    parent::__construct($query, $data);
    $this->original = $data;
  }

  /**
   * 删除当前行记录
   *
   * @param bool $real 是否硬删除，默认 false；仅模型查询结果支持 $real 参数
   * @return int 成功返回 1，失败返回 0
   * @throws RuntimeException 缺少主键字段时抛出
   * @throws DbException 数据库操作失败时抛出
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
   * 设置字段值，自动追踪变更字段
   *
   * @param mixed $key 字段名
   * @param mixed $value 字段值
   */
  public function offsetSet(mixed $key, mixed $value): void
  {
    $originalValue = $this->original[$key] ?? null;
    if ($value !== $originalValue) {
      // 与原始快照不同才视为变更（同字段多次修改不重复记录）
      if (!in_array($key, $this->change, true)) $this->change[] = $key;
    } elseif (in_array($key, $this->change, true)) {
      // 值改回原始值：从变更列表移除，避免 save() 产生无意义 UPDATE
      $this->change = array_values(array_diff($this->change, [$key]));
    }
    parent::offsetSet($key, $value);
  }

  /**
   * 清空变更追踪，将当前行标记为已同步
   *
   * 外部批量写库（如 Collection::update）成功后同步数据到行时调用，
   * 避免后续 save() 把刚同步的字段再次写回数据库。
   * 同步刷新原始快照，使后续变更判断以最新数据为基准。
   */
  public function markSynced(): void
  {
    $this->change = [];
    $this->original = $this->getArrayCopy();
  }

  /**
   * 合并数据到当前行，相同字段被新数据覆盖
   *
   * 合并后需调用 save() 才能持久化到数据库。
   *
   * @param array $data 要合并的键值对
   * @return static 支持链式调用
   */
  public function merge(array $data): static
  {
    foreach ($data as $key => $value) {
      $this[$key] = $value;
    }
    return $this;
  }

  /**
   * 将变更的字段持久化到数据库
   *
   * 仅更新被修改过的字段，无变更时返回 false。
   *
   * @return bool 更新成功返回 true，无变更或失败返回 false
   * @throws RuntimeException 缺少主键字段时抛出
   * @throws DbException 数据库操作失败时抛出
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
   * 获取指定列的值
   *
   * @param string $column 列名
   * @return mixed 列值
   */
  public function value(string $column): mixed
  {
    return $this->offsetGet($column);
  }
}
