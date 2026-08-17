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
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model\Query;

/**
 * 数据集合，可通过数组方式访问和操作多行查询结果
 *
 * 继承 BaseCollection，提供过滤、排序、聚合、分块等集合操作方法，
 * 同时支持批量更新、批量删除等数据库操作。
 *
 * @see BaseCollection
 */
class Collection extends BaseCollection
{
  /**
   * 构建集合，将原始数据行转换为 DataSet 对象
   *
   * @param BaseQuery|Query $query 查询对象，用于后续的数据库操作
   * @param array $data 查询结果原始数据
   */
  public function __construct(protected BaseQuery|Query $query, array $data)
  {
    /**
     * 遍历数据集，将每个元素转换为DataSet对象
     */
    array_walk($data, function (&$item) {
      $item = is_array($item) ? new DataSet($this->query->newQuery(), $item) : $item;
    });
    parent::__construct($query, $data);
  }

  /**
   * 获取集合中第一行数据
   *
   * @return DataSet|null 第一行数据，集合为空时返回 null
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
   * $filtered = $collection->filter(function (DataSet $row) { return $row->age > 25; });
   * ```
   *
   * @param callable $callback 过滤回调，返回 true 时保留该行
   * @return Collection 过滤后的新集合
   */
  public function filter(callable $callback): Collection
  {
    return $this->cloneSelf(array_filter($this->getArrayCopy(), $callback));
  }

  /**
   * 基于新数据克隆当前集合，保留隐藏字段和获取器配置
   *
   * @param array $data 新的数据数组
   * @return static 克隆后的集合实例
   */
  private function cloneSelf(array $data): static
  {
    $instance = new static($this->query, $data);
    $instance->hidden(...$this->hidden);
    foreach ($this->withAttr as $key => $value) {
      $instance->withAttr($key, $value);
    }
    return $instance;
  }

  /**
   * 对集合中每一行数据应用回调函数，返回转换后的新集合
   *
   * @param callable $callback 转换回调，接收 DataSet 返回转换后的值
   * @return static 转换后的新集合
   */
  public function map(callable $callback): static
  {
    return $this->cloneSelf(array_map($callback, $this->getArrayCopy()));
  }

  /**
   * 获取集合中最后一行数据
   *
   * @return DataSet|null 最后一行数据，集合为空时返回 null
   */
  public function last(): ?DataSet
  {
    $arrayCopy = $this->getArrayCopy();
    return end($arrayCopy) ?: null;
  }

  /**
   * 获取所有数据的 DataSet 数组
   *
   * @return DataSet[]
   * @see static::getArrayCopy()
   */
  public function all(): array
  {
    return $this->getArrayCopy();
  }

  /**
   * 反向过滤，返回不满足条件的元素组成的新集合
   *
   * @param callable $callback 过滤回调，返回 true 时排除该行
   * @return Collection 过滤后的新集合
   */
  public function reject(callable $callback): Collection
  {
    return $this->cloneSelf(
      array_filter(
        $this->getArrayCopy(),
        function ($item) use ($callback) {
          return !$callback($item);
        }
      )
    );
  }

  /**
   * 按指定列降序排序
   *
   * @param string $column 排序列名
   * @return Collection 排序后的新集合
   */
  public function sortByDesc(string $column): Collection
  {
    return $this->sortBy($column, SORT_DESC);
  }

  /**
   * 按指定列对集合进行排序
   *
   * @param string $column 排序列名
   * @param int $sortOrder 排序方向 SORT_ASC|SORT_DESC，默认 SORT_ASC
   * @return Collection 排序后的新集合
   */
  public function sortBy(string $column, int $sortOrder = SORT_ASC): Collection
  {
    $items = $this->getArrayCopy();
    // 修复#12: 使用<=>飞船操作符替代比较逻辑，语义更明确
    usort($items, function ($a, $b) use ($column, $sortOrder) {
      $aVal = $a[$column] ?? null;
      $bVal = $b[$column] ?? null;
      $comparison = $aVal <=> $bVal;
      return $sortOrder === SORT_DESC ? -$comparison : $comparison;
    });
    return $this->cloneSelf($items);
  }

  /**
   * 计算集合中指定列的平均值
   *
   * @param string $attribute 列名
   * @return float|int 平均值，集合为空时返回 0
   */
  public function avg(string $attribute): float|int
  {
    // 修复: 空集合时 count 为 0 会触发 DivisionByZeroError，空集合直接返回 0
    if (count($this) === 0) return 0;
    $sum = $this->sum($attribute);
    return $sum / count($this);
  }

  /**
   * 计算集合中指定列的总和
   *
   * @param string $column 列名，字段值必须为 int 或 float 类型
   * @return int|float 总和，集合为空时返回 0
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
   * 获取集合中指定列的最大值
   *
   * @param string $column 列名，字段值必须为 int 或 float 类型
   * @return int|float|null 最大值，集合为空或列值全为 NULL 时返回 null
   * @throws InvalidArgumentException 字段值非数值类型时抛出
   */
  public function max(string $column): int|float|null
  {
    $maxValue = null;
    foreach ($this as $item) {
      // NULL 值跳过（与 SQL 聚合语义一致），而非按 0 参与比较
      $value = $item[$column] ?? null;
      if ($value === null) continue;
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
   * 获取集合中指定列的最小值
   *
   * @param string $column 列名，字段值必须为 int 或 float 类型
   * @return int|float|null 最小值，集合为空或列值全为 NULL 时返回 null
   * @throws InvalidArgumentException 字段值非数值类型时抛出
   */
  public function min(string $column): int|float|null
  {
    $minValue = null;
    foreach ($this as $item) {
      // NULL 值跳过（与 SQL 聚合语义一致），而非按 0 参与比较
      $value = $item[$column] ?? null;
      if ($value === null) continue;
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
   * 追加一行数据到集合中
   *
   * @param mixed $value DataSet 对象或关联数组
   * @param bool $autoWrite 传入数组且为 true 时，自动写入数据库
   * @throws DbException 数据库操作失败时抛出
   */
  public function append(mixed $value, bool $autoWrite = false): void
  {
    if ($value instanceof DataSet) {
      parent::append($value);
    } else {
      if (!is_array($value)) {
        throw new InvalidArgumentException('Value must be an array or DataSet object.');
      }
      $query = $this->query->newQuery();
      if ($autoWrite) {
        $id = $query->strict(false)->insertGetId($value);
        $value[$this->query->getPrimaryKey()] = $id;
      }
      parent::append(new DataSet($query, $value));
    }
  }

  /**
   * 删除集合中所有记录
   *
   * 前提：集合中每行记录必须存在主键字段。
   *
   * @param bool $real 是否硬删除，默认 false；仅模型查询结果支持 $real 参数
   * @return int 成功删除的记录数，失败返回 0
   * @throws RuntimeException 缺少主键字段时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function delete(bool $real = false): int
  {
    $pk = $this->query->getPrimaryKey();
    $pkList = $this->getPks($pk);
    // 空集合没有可删除的记录，直接返回 0 而非构造空 IN 条件
    if ($pkList === []) return 0;
    return $this->query->whereIn($pk, $pkList)->delete($real);
  }

  /**
   * 收集集合中所有行的主键值
   *
   * @param string $pk 主键字段名
   * @return array 主键值数组，集合为空时返回空数组
   * @throws RuntimeException 某行缺少主键字段时抛出
   */
  private function getPks(string $pk): array
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
    return $pkList;
  }

  /**
   * 根据字段值过滤集合中的元素
   *
   * @param string $column 字段名
   * @param mixed $value 匹配值
   * @return Collection 过滤后的新集合
   */
  public function where(string $column, mixed $value): Collection
  {
    return $this->cloneSelf(
      array_filter(
        $this->getArrayCopy(),
        function ($item) use ($column, $value) {
          return isset($item[$column]) && $item[$column] == $value;
        }
      )
    );
  }

  /**
   * 批量更新集合中所有记录
   *
   * @param array $data 要更新的键值对
   * @return int 成功更新的记录数
   * @throws DbException 数据库操作失败时抛出
   */
  public function update(array $data): int
  {
    $pk = $this->query->getPrimaryKey();
    $pkList = $this->getPks($pk);
    // 空集合没有可更新的记录，直接返回 0 而非构造空 IN 条件
    if ($pkList === []) return 0;
    $result = $this->query->strict(false)->whereIn($pk, $pkList)->update($data);
    if ($result) {
      // 更新每一行数据
      $this->each(function (DataSet $row) use ($data) {
        $row->merge($data);
        // 同步的是已落库数据，清空变更标记防止 save() 重复更新
        $row->markSynced();
      });
    }
    return $result;
  }

  /**
   * 遍历集合中的每一行数据
   *
   * @param callable $callback 回调函数，接收 DataSet 参数
   */
  public function each(callable $callback): void
  {
    foreach ($this->getIterator() as $row) {
      $callback($row);
    }
  }

  /**
   * 按指定字段排序，默认按主键排序，直接修改当前集合
   *
   * @param int $flags 排序方向 SORT_ASC|SORT_DESC
   * @param string|null $column 排序列名，为 null 时使用主键
   * @return true 始终返回 true
   */
  public function asort(int $flags = SORT_ASC, ?string $column = null): true
  {
    $pk = is_null($column) ? $this->query->getPrimaryKey() : $column;
    // 自定义排序函数
    $sortFunction = function ($a, $b) use ($pk, $flags) {
      // 获取主键值
      $aPkValue = $a[$pk] ?? 0;
      $bPkValue = $b[$pk] ?? 0;
      // 根据 $flags 进行排序
      if (is_string($aPkValue)) {
        if ($flags === SORT_ASC) {
          return strcmp($aPkValue, $bPkValue);
        } else {
          return strcmp($bPkValue, $aPkValue);
        }
      } else {
        if ($flags === SORT_ASC) {
          return $aPkValue <=> $bPkValue;
        } else {
          return $bPkValue <=> $aPkValue;
        }
      }
    };
    return $this->uasort($sortFunction);
  }

  /**
   * 对集合中每一行数据的键进行排序
   *
   * @param int $flags 排序标志，默认 SORT_REGULAR
   * @return true 始终返回 true
   */
  public function ksort(int $flags = SORT_REGULAR): true
  {
    foreach ($this as $row) {
      $row->ksort($flags);
    }
    return true;
  }

  /**
   * 将集合按指定大小分块
   *
   * @param int $size 每块的元素数量
   * @return Collection[] 分块后的集合数组
   */
  public function chunk(int $size): array
  {
    $chunks = array_chunk($this->getArrayCopy(), $size);
    array_walk($chunks, function (&$chunk) {
      $chunk = $this->cloneSelf($chunk);
    });
    return $chunks;
  }

  /**
   * 通过数组方式添加记录，仅接受 DataSet 对象或关联数组
   *
   * @param mixed $key 键名
   * @param mixed $value DataSet 对象或关联数组
   * @throws InvalidArgumentException 值类型不合法时抛出
   */
  public function offsetSet(mixed $key, mixed $value): void
  {
    if (!$value instanceof DataSet && !is_array($value)) {
      throw new InvalidArgumentException('往集合中添加的记录必须是DataSet对象或数组');
    }
    if (is_array($value)) {
      $value = new DataSet($this->query->newQuery(), $value);
    }
    parent::offsetSet($key, $value);
  }
}
