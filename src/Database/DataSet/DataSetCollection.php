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

namespace Viswoole\Database\DataSet;

use InvalidArgumentException;
use Override;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * 查询结果数据集
 */
class DataSetCollection extends ArrayObject
{
  /**
   * @var array<int,array<string,mixed>>|array<string,mixed> 数据集列表
   */
  protected array $data = [];

  /**
   * @param array<int,array<string,mixed>> $list
   */
  public function __construct(array $list, array $sqlRunInfo, Driver $driver)
  {
    $this->data = $list;
  }

  /**
   * 创建一个新的数据集
   *
   * @param array $list
   * @return static
   */
  public static function create(array $list): static
  {
    return new static($list);
  }

  /**
   * 获取全部数据
   *
   * @return array 数组
   */
  public function fetchAll(): array
  {
    return $this->data;
  }

  /**
   * 合并数组
   *
   * @access public
   * @param mixed $items 数据
   * @return array
   */
  public function merge(mixed $list): array
  {
    return array_merge($this->data, $list);
  }

  /**
   * 是否为空
   * @access public
   * @return bool
   */
  public function isEmpty(): bool
  {
    return empty($this->data);
  }

  /**
   * 把数据集行切割为多个指定大小的数据集.
   *
   * @access public
   * @param int $size 块大小
   * @param bool $preserveKeys 设为 true，可以使 PHP 保留输入数组中原来的键名。
   * 如果你指定了 false，那每个结果数组将用从零开始的新数字索引。默认值是 false
   * @return array
   */
  public function chunk(int $size, bool $preserveKeys = false): array
  {
    $chunks = [];
    foreach (array_chunk($this->data, $size, $preserveKeys) as $chunk) {
      $chunks[] = new static($chunk);
    }
    return $chunks;
  }

  /**
   * 从数据集中移除第一行，并删除该数据集
   *
   * @access public
   * @return Row
   */
  public function shift(): Row
  {
    return array_shift($this->data);
  }

  /**
   * 通过数组方式获取行
   *
   * @param mixed $offset
   * @return Row
   */
  #[Override] public function offsetGet(mixed $offset): Row
  {
    return parent::offsetGet($offset);
  }

  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    if (!$value instanceof Row && !is_array($value)) {
      throw new InvalidArgumentException('The value must be an array or Row object');
    }
    parent::offsetSet($offset, $value);
  }
}
