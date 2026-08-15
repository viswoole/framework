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

declare(strict_types=1);

namespace Viswoole\Database\Collection;

use ArrayObject;
use JsonSerializable;
use Override;
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Model\Query;


/**
 * 数据集合基类
 *
 * 继承 ArrayObject 实现数组式访问，提供 JSON 序列化、隐藏字段过滤、获取器等通用能力。
 * 子类 Collection 和 DataSet 分别处理多行和单行数据。
 *
 * @see Collection
 * @see DataSet
 */
abstract class BaseCollection extends ArrayObject implements JsonSerializable
{
  protected int $flags = 0;
  /** @var array 获取器回调列表，键为列名，值为回调函数 */
  protected array $withAttr = [];
  /** @var array 需要隐藏的字段名列表，支持点号分隔的嵌套字段 */
  protected array $hidden = [];

  /**
   * 构建集合，同步模型查询的隐藏字段配置
   *
   * @param BaseQuery|Query $query 查询对象，用于后续数据库操作
   * @param array $data 查询结果原始数据
   */
  public function __construct(
    protected BaseQuery|Query $query,
    array                     $data
  ) {
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
   * 将数据集合递归转换为数组，应用获取器和隐藏字段过滤
   *
   * @param bool $withAttr 是否应用获取器，默认 true
   * @param bool $hidden 是否过滤隐藏字段，默认 true
   * @param int $maxDepth 最大递归深度，默认 10，防止嵌套集合无限递归
   * @return array 转换后的数组数据
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
          // 修复: 传播集合级获取器到子集合——多行 Collection 中字段转换发生在子 DataSet
          // 的递归 toArray 内，此前配置仅存于外层导致 withAttr 对多行集合永不生效
          foreach ($this->withAttr as $attrColumn => $attrCallback) {
            $value->withAttr($attrColumn, $attrCallback);
          }
          $value = $value->toArray($withAttr, $hidden, $maxDepth - 1);
        }
      }
      // 修复#7: removeHiddenKeys通过引用修改参数，需要确保对数组类型的value也生效
      if (is_array($value)) {
        $this->removeHiddenKeys($value, $this->hidden);
      }
      // 修复: 此前 $withAttr 形参未参与判断，传 false 仍会应用获取器
      if ($withAttr && is_string($key)) {
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
   * 递归移除隐藏字段，支持点号分隔的嵌套字段路径
   *
   * @param array $data 数据引用
   * @param array $hidden 需要隐藏的字段路径列表
   * @param string $parentKey 父级字段路径前缀
   */
  private function removeHiddenKeys(array &$data, array $hidden, string $parentKey = ''): void
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
   * 将集合转换为 JSON 字符串，中文字符保持原样输出
   *
   * @return string JSON 格式字符串
   */
  public function __toString(): string
  {
    return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
  }

  /**
   * 判断集合是否为空
   *
   * @return bool 集合为空返回 true
   */
  public function isEmpty(): bool
  {
    return $this->count() === 0;
  }

  /**
   * 定义获取器，在 toArray 时对指定列的值进行转换
   *
   * @param string $column 列名
   * @param callable $callback 转换回调
   * @return static 支持链式调用
   */
  public function withAttr(string $column, callable $callback): static
  {
    $this->withAttr[$column] = $callback;
    return $this;
  }

  /**
   * 设置要隐藏的字段，支持点号分隔的嵌套字段路径
   *
   * 示例：
   * ```
   * $collection->hidden('password');           // 隐藏 password 字段
   * $collection->hidden('address.city');        // 隐藏嵌套的 address.city 字段
   * ```
   *
   * @param string ...$column 要隐藏的字段路径
   * @return static 支持链式调用
   */
  public function hidden(string ...$column): static
  {
    $this->hidden = array_merge($this->hidden, $column);
    return $this;
  }

  /**
   * 克隆时深拷贝查询对象和数据
   */
  public function __clone(): void
  {
    $this->query = clone $this->query;
    $this->exchangeArray($this->getArrayCopy());
  }

  /**
   * 获取集合数据的深度拷贝数组
   *
   * Collection 调用时返回包含所有 DataSet 行的数组；
   * 如需将每行也转为数组，请使用 toArray() 方法。
   *
   * @return array DataSet 对象数组
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
   * 前提：集合中每行记录必须存在主键字段。
   *
   * @param bool $real 是否硬删除，默认 false；仅模型查询结果支持 $real 参数
   * @return int 成功删除的记录数
   */
  abstract public function delete(bool $real = false): int;
}
