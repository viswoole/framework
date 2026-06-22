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
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model;

/**
 * 关联查询
 *
 * 处理模型间的一对一和一对多关联关系，通过外键映射将关联数据填充到主数据中。
 * 支持通过 handle() 方法对关联查询添加额外条件。
 *
 * @see Model::hasOne()
 * @see Model::hasMany()
 */
class RelationQuery
{
  /** @var callable|null 对关联查询添加额外条件的回调 */
  protected mixed $handle;

  /**
   * @param Model $relationModel 关联模型实例
   * @param string $foreignKey 关联模型中的外键名
   * @param string $localKey 当前模型的主键名
   * @param bool $many 是否一对多关联，默认 false（一对一）
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
   * 查询关联数据并按外键映射填充到主数据中
   *
   * @param array $data 主表查询结果
   * @param string $name 关联名称，作为填充到主数据中的键名
   * @return array 填充关联数据后的主数据
   * @throws DbException 数据库操作失败时抛出
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
   * 设置关联查询的额外条件回调，在查询执行前调用
   *
   * @param callable $handle 接收 Query 实例的回调
   * @return static 支持链式调用
   */
  public function handle(callable $handle): static
  {
    $this->handle = $handle;
    return $this;
  }
}
