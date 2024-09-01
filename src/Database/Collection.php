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

namespace Viswoole\Database;

use InvalidArgumentException;
use Override;
use RuntimeException;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\Collection\DataSet;

/**
 * 数据集合，可通过数组方式访问
 */
class Collection extends BaseCollection
{
  /**
   * 数据集列表
   *
   * @param Query|Model $query 查询对象
   * @param array $data 查询结果
   */
  public function __construct(protected Query|Model $query, array $data)
  {
    /**
     * 遍历数据集，将每个元素转换为Row对象
     */
    array_walk($data, function (&$item) {
      $item = is_array($item) ? new DataSet(clone $this->query, $item) : $item;
    });
    parent::__construct($query, $data);
  }

  /**
   * 获取第一行数据
   *
   * @return DataSet|null 如果集合为空，则返回null。
   */
  public function first(): ?DataSet
  {
    return $this->getArrayCopy()[0] ?? null;
  }

  /**
   * 根据回调函数过滤集合中的元素
   *
   * 示例:
   * ```
   * // 通过以下方式获得满足条件的数据集列表
   * $filtered = $collection->filter(function (Row $row) { return $row->age > 25; });
   * print_r($filtered->toArray());
   * ```
   *
   * @param callable $callback 回调函数
   * @return Collection 过滤后的集合
   */
  public function filter(callable $callback): Collection
  {
    return new self($this->query, array_filter($this->getArrayCopy(), $callback));
  }

  /**
   * 给当前集合中的每一行数据应用回调函数
   *
   * @param callable $callback
   * @return static
   */
  public function map(callable $callback): static
  {
    return new self($this->query, array_map($callback, $this->getArrayCopy()));
  }

  /**
   * 返回集合中的最后一行数据
   *
   * @return DataSet|null 如果集合为空，则返回null。
   */
  public function last(): ?DataSet
  {
    $arrayCopy = $this->getArrayCopy();
    return end($arrayCopy) ?: null;
  }

  /**
   * 获取所有数据
   *
   * @return DataSet[]
   */
  public function all(): array
  {
    return $this->getArrayCopy();
  }

  /**
   * 反向过滤，返回不满足条件的元素集合
   *
   * @access public
   * @param callable $callback 回调函数
   * @return Collection 过滤后的集合
   */
  public function reject(callable $callback): Collection
  {
    return new Collection(
      $this->query,
      array_filter(
        $this->getArrayCopy(),
        function ($item) use ($callback) {
          return !$callback($item);
        }
      )
    );
  }

  /**
   * 降序排序
   *
   * @access public
   * @param string $column 排序列名
   * @return Collection 排序后的集合
   */
  public function sortByDesc(string $column): Collection
  {
    return $this->sortBy($column, SORT_DESC);
  }

  /**
   * 根据指定的属性对集合进行排序
   *
   * @access public
   * @param string $column 排序列名
   * @param int $sortOrder 排序顺序 (SORT_ASC 或 SORT_DESC)
   * @return Collection 排序后的集合
   */
  public function sortBy(string $column, int $sortOrder = SORT_ASC): Collection
  {
    $items = $this->getArrayCopy();
    usort($items, function ($a, $b) use ($column, $sortOrder) {
      if ($a[$column] == $b[$column]) {
        return 0;
      }
      return ($sortOrder == SORT_ASC)
        ? ($a[$column] < $b[$column] ? -1 : 1)
        : ($a[$column] > $b[$column] ? -1 : 1);
    });
    return new Collection($this->query, $items);
  }

  /**
   * 计算集合中所有元素特定属性的平均值
   *
   * @access public
   * @param string $attribute 属性名
   * @return float|int 平均值，如果集合为空，则返回0
   */
  public function avg(string $attribute): float|int
  {
    $sum = $this->sum($attribute);
    return $sum / count($this);
  }

  /**
   * 计算集合中所有行特定列的总和
   *
   * @param string $column 字段名,字段数据类型必须为int、float
   * @return int|float 总和，如果集合为空，则返回0
   */
  public function sum(string $column): int|float
  {
    $sum = 0;
    foreach ($this as $item) {
      $value = $item[$column] ?? 0;
      $sum += $value;
    }
    return $sum;
  }

  /**
   * 找到集合中某个列的最大值
   *
   * @param string $column 字段名称，类型必须为int、float
   * @return int|float|null 最大值,数据集为空时返回null
   */
  public function max(string $column): int|float|null
  {
    $maxValue = null;
    foreach ($this as $item) {
      $value = $item[$column] ?? 0;
      // 检查值是否为 int 或 float 类型
      if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException(
          "Column '$column' must contain values of type int or float."
        );
      }
      if ($maxValue === null || $value > $maxValue) {
        $maxValue = $value;
      }
    }
    return $maxValue;
  }

  /**
   * 找到集合中某个列的最小值
   *
   * @param string $column 字段名称，类型必须为int、float
   * @return int|float|null 最大值,数据集为空时返回null
   */
  public function min(string $column): int|float|null
  {
    $minValue = null;
    foreach ($this as $item) {
      $value = $item[$column] ?? 0;
      // 检查值是否为 int 或 float 类型
      if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException(
          "Column '$column' must contain values of type int or float."
        );
      }
      if ($minValue === null || $value < $minValue) {
        $minValue = $value;
      }
    }
    return $minValue;
  }

  /**
   * @param mixed $value
   * @return void
   */
  public function append(mixed $value): void
  {
    if ($value instanceof DataSet) {
      parent::append($value);
    } else {
      if (!is_array($value)) {
        throw new InvalidArgumentException('Value must be an array or Row object.');
      }
      parent::append(new DataSet($this->query, $value));
    }
  }

  /**
   * 删除集合中的所有记录
   *
   * 前提条件是集合中的每一行记录都必须存在主键字段。
   *
   * @param bool $real 是否为硬删除，默认为false，仅模型查询结果支持$real参数。
   * @return int 成功返回删除的记录数，失败返回0。
   * @throws RuntimeException 如果缺少主键字段
   */
  #[Override] public function delete(bool $real = false): int
  {
    $pk = $this->query->getPrimaryKey();
    $pkList = $this->getPks($pk);
    return $this->query->whereIn($pk, $pkList)->delete($real);
  }

  /**
   * 获取所有行主键
   *
   * @param string $pk
   * @return array|int
   */
  private function getPks(string $pk): array|int
  {
    $pkList = [];
    foreach ($this as /** @var DataSet $row */ $row) {
      if (isset($row[$pk])) {
        $pkList[] = $row[$pk];
      } else {
        throw new RuntimeException(
          "操作失败，缺少主键字段($pk) index:$row"
        );
      }
    }
    if (empty($pkList)) return 0;
    return $pkList;
  }

  /**
   * 根据字段值过滤集合中的元素
   *
   * @param string $column 字段名
   * @param mixed $value 字段值
   * @return Collection 过滤后的集合
   */
  public function where(string $column, mixed $value): Collection
  {
    return new Collection(
      $this->query,
      array_filter(
        $this->getArrayCopy(),
        function ($item) use ($column, $value) {
          return isset($item[$column]) && $item[$column] == $value;
        }
      )
    );
  }

  /**
   * 批量更新数据
   *
   * @param array $data
   * @return int 返回更新的记录数。
   */
  public function update(array $data): int
  {
    $pk = $this->query->getPrimaryKey();
    $pkList = $this->getPks($pk);
    $result = $this->query->whereIn($pk, $pkList)->update($data);
    if ($result) {
      // 更新每一行数据
      $this->each(function (DataSet $row) use ($data) {
        $row->merge($data);
      });
    }
    return $result;
  }

  /**
   * 遍历集合中的每一行数据
   *
   * @param callable $callback
   * @return void
   */
  public function each(callable $callback): void
  {
    foreach ($this->getIterator() as $row) {
      $callback($row);
    }
  }
}
