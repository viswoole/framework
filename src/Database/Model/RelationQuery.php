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

namespace Viswoole\Database\Model;

use Viswoole\Database\Collection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Model;

/**
 * 关联查询
 */
class RelationQuery
{
  /**
   * @var callable 处理查询的回调
   */
  protected mixed $handle;

  /**
   * @param Model $relationModel 关联的模型
   * @param string $foreignKey 当前模型在关联模型中的外键，如果有中间模型时则是在中间模型中的外键
   * @param string $localKey 当前模型主键
   * @param bool $many 是否对多关联，默认为false，表示一对一关联
   */
  public function __construct(
    protected Model  $relationModel,
    protected string $foreignKey,
    protected string $localKey,
    protected bool   $many = false
  )
  {
  }

  /**
   * 查询关联数据
   *
   * @param array $data 数据
   * @param string $name 关联名称
   * @return array
   */
  public function query(array $data, string $name): array
  {
    $keys = array_column($data, $this->localKey);
    // 构建查询实例
    $query = $this->relationModel->query->whereIn($this->foreignKey, $keys);
    // 交给处理回调，处理查询。
    if (isset($this->handle)) {
      call_user_func($this->handle, $query);
    }
    $list = $query->getArray();
    $keyMapData = [];
    foreach ($list as $row) {
      $key = $row[$this->foreignKey];
      if ($this->many) {
        $row = new DataSet(
          $this->relationModel->query->newQuery(), $row
        );
        if (array_key_exists($key, $keyMapData)) {
          /**
           * @var Collection $collection
           */
          $collection = $keyMapData[$key];
          $collection->append($row);
        } else {
          $collection = new Collection($this->relationModel->query, [$row]);
          $keyMapData[$key] = $collection;
        }

      } else {
        // 如果是一对一关联，则只保留一条数据，多余数据丢弃
        if (array_key_exists($key, $keyMapData)) continue;
        $keyMapData[$key] = new DataSet(
          $this->relationModel->query->newQuery(), $row
        );
      }
    }
    // 遍历数据，将关联数据填充到主数据中
    array_walk($data, function (&$row) use ($keyMapData, $name) {
      $key = $row[$this->localKey];
      if (!array_key_exists($key, $keyMapData)) {
        $value = $this->many ?
          new Collection($this->relationModel->query->newQuery(), [])
          : new DataSet($this->relationModel->query->newQuery(), []);
      } else {
        $value = $keyMapData[$key];
      }
      $row[$name] = $value;
    });
    // 返回主数据
    return $data;
  }

  /**
   * 通过该方法设置中间件，可以在关联查询时添加一些自定义的查询条件。
   *
   * @param callable $handle
   * @return static
   */
  public function handle(callable $handle): static
  {
    $this->handle = $handle;
    return $this;
  }
}
