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
use JsonSerializable;
use Override;
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Model\Query;


/**
 * 数据集合基类
 */
abstract class BaseCollection extends ArrayObject implements JsonSerializable
{
  protected int $flags = 0;
  /**
   * @var array 获取器
   */
  protected array $withAttr = [];
  /**
   * @var array 要隐藏的字段
   */
  protected array $hidden = [];

  /**
   * @param BaseQuery|Query $query 查询对象
   * @param array $data 查询结果
   */
  public function __construct(
    protected BaseQuery|Query $query,
    array                     $data
  )
  {
    // 同步隐藏字段
    if ($this->query instanceof Query) {
      $this->hidden = array_merge($this->hidden, $this->query->getHiddenColumn());
    }
    parent::__construct($data, $this->flags);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function jsonSerialize(): array
  {
    return $this->toArray();
  }

  /**
   * 将数据集合转换为数组格式
   *
   * 该方法会递归处理嵌套的数据集合，应用获取器转换和隐藏字段过滤。
   * 支持通过最大深度参数控制递归层级，防止无限递归导致的性能问题。
   *
   * @param bool $withAttr 是否应用获取器对数据进行处理，默认为true
   * @param bool $hidden 是否应用隐藏字段过滤，默认为true
   * @param int $maxDepth 最大递归深度，默认值为10，用于防止嵌套集合无限递归
   * @return array 转换后的数组数据，键值对结构与原始集合保持一致
   */
  public function toArray(bool $withAttr = true, bool $hidden = true, int $maxDepth = 10): array
  {
    $withAttrColumn = array_keys($this->withAttr);
    $array = [];
    foreach ($this as $key => $value) {
      if ($value instanceof BaseCollection) {
        // 修复#18: 递归深度检查，超过最大深度时停止递归
        if ($maxDepth <= 0) {
          $value = [];
        } else {
          $value = $value->toArray($withAttr, $hidden, $maxDepth - 1);
        }
      }
      // 修复#7: removeHiddenKeys通过引用修改参数，需要确保对数组类型的value也生效
      if (is_array($value)) {
        $this->removeHiddenKeys($value, $this->hidden);
      }
      if (is_string($key)) {
        if (in_array($key, $withAttrColumn)) {
          $value = $this->withAttr[$key]($value);
        } elseif ($this->query instanceof Query) {
          $value = $this->query->withGetAttr($key, $value);
        }
      }
      $array[$key] = $value;
    }
    return $array;
  }

  /**
   * 删除隐藏字段
   *
   * @param $data
   * @param array $hidden
   * @param string $parentKey
   * @return void
   */
  private function removeHiddenKeys(&$data, array $hidden, string $parentKey = ''): void
  {
    if (!is_numeric(implode('', array_keys($data)))) {
      foreach ($data as $key => &$value) {
        if (is_int($key)) continue;
        $currentKey = $parentKey ? $parentKey . '.' . $key : $key;
        // 检查当前键是否在需要隐藏的列表中
        if (in_array($currentKey, $hidden)) {
          unset($data[$key]); // 删除该键
        } elseif (is_array($value)) { // 如果值还是数组，则递归处理
          $this->removeHiddenKeys($value, $hidden, $currentKey);
        }
      }
    }
  }

  /**
   * 将数据集合转换为JSON字符串表示
   *
   * 该方法实现了PHP的__toString魔术方法，当对象被当作字符串使用时自动调用。
   * 内部通过toArray()方法将集合转换为数组，再编码为JSON格式，确保中文字符不被转义。
   *
   * @return string JSON格式的字符串，包含集合的所有数据，中文字符保持原样输出
   */
  public function __toString(): string
  {
    return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
  }

  /**
   * 判断是否为空
   *
   * @return bool
   */
  public function isEmpty(): bool
  {
    return $this->count() === 0;
  }

  /**
   * 定义获取器
   *
   * @access public
   * @param string $column
   * @param callable $callback
   * @return static
   */
  public function withAttr(string $column, callable $callback): static
  {
    $this->withAttr[$column] = $callback;
    return $this;
  }

  /**
   * 要隐藏的字段
   *
   * 示例：
   * ```
   * // 该方法对多行数据集和单行数据集都有效
   * $collection = $query->table('user')->select();
   * // 隐藏所有数据的password字段
   * $collection->hidden('password');
   * // 隐藏所有数据的address.city字段，用.可以嵌套层级
   * $collection->hidden('address.city');
   * ```
   *
   * @param string ...$column
   * @return static
   */
  public function hidden(string ...$column): static
  {
    $this->hidden = array_merge($this->hidden, $column);
    return $this;
  }

  /**
   * 克隆
   *
   * @return void
   */
  public function __clone(): void
  {
    $this->query = clone $this->query;
    $this->exchangeArray($this->getArrayCopy());
  }

  /**
   * 该方法用于获取当前集合的数组（深度）拷贝，并返回一个新的数组。
   *
   * 如果Collection对象调用该方法，则返回的是一个包含所有行的数组。行元素依然是DataSet对象，
   * 如果你需要将每一行数据都转换为数组，请使用toArray()方法。
   *
   * @return array
   */
  public function getArrayCopy(): array
  {
    $arrayCopy = [];
    foreach ($this->getIterator() as $key => $row) {
      if ($row instanceof BaseCollection) $row = clone $row;
      $arrayCopy[$key] = $row;
    }
    return $arrayCopy;
  }

  /**
   * 删除集合中的所有记录
   *
   * 前提条件是集合中的每一行记录都必须存在主键字段。
   *
   * @param bool $real 是否为硬删除，默认为false，仅模型查询结果支持$real参数。
   * @return int
   */
  abstract public function delete(bool $real = false): int;
}
