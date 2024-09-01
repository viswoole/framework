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
use Viswoole\Database\Model;
use Viswoole\Database\Query;

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
   * @param Query $query 查询对象
   * @param array $data 查询结果
   */
  public function __construct(
    protected Query|Model $query,
    array                 $data
  )
  {
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
   * 转换为数组
   *
   * @access public
   * @param bool $withAttr 是否使用获取器对数据进行处理
   * @return array
   */
  public function toArray(bool $withAttr = true): array
  {
    $array = [];
    foreach ($this as $key => $value) {
      if ($value instanceof DataSet) {
        $value = $value->toArray();
      }
      // 应用获取器
      if ($withAttr && isset($this->withAttr[$key])) {
        $value = $this->withAttr[$key]($value);
      }
      $array[$key] = $value;
    }
    return $array;
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
   * @param string $key
   * @param callable $callback
   * @return static
   */
  public function withAttr(string $key, callable $callback): static
  {
    $this->withAttr[$key] = $callback;
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
   * 如果Collection对象调用该方法，则返回的是一个包含所有行的数组。行元素依然是Row对象，
   * 如果你需要将Row也转换为数组，请使用toArray()方法。
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
