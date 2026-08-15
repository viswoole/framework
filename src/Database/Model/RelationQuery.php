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
   * 查询关联数据并构建外键值到关联结果的映射
   *
   * 注意：本方法只基于传入的 $data 提取外键值并查询关联数据，
   * 不修改、不返回主数据本身——多关联并发查询时各协程基于同一份主数据
   * 快照独立计算，由调用方（Query::queryRelationData）串行合并，
   * 避免协程间相互覆盖已填充的关联字段。
   *
   * @param array $data 主表查询结果（仅读取其中的 localKey 列）
   * @param string $name 关联名称（仅用于异常信息，不再作为填充键名）
   * @return array<mixed,DataSet|Collection> 外键值 => 关联数据（一对一为 DataSet，一对多为 Collection）
   * @throws DbException 数据库操作失败时抛出
   */
  public function query(array $data, string $name): array
  {
    $keys = array_column($data, $this->localKey);
    // 过滤 null 并去重：null 参与 IN 永假且占位，重复外键导致无谓的巨型 IN 列表
    $keys = array_values(array_unique(array_filter($keys, fn($v) => $v !== null)));
    // 主数据无有效外键值时无需查询，直接返回空映射，
    // 由调用方为每行填充空集合/空数据集（避免 whereIn 因空数组误报异常）
    if ($keys === []) return [];
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
    return $keyMapData;
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

  /**
   * 获取当前模型的主键名（关联查询的本地键）
   *
   * @return string 本地键字段名
   */
  public function localKey(): string
  {
    return $this->localKey;
  }

  /**
   * 判断是否为一对多关联
   *
   * @return bool 一对多返回 true，一对一返回 false
   */
  public function isMany(): bool
  {
    return $this->many;
  }

  /**
   * 获取关联模型实例
   *
   * @return Model 关联模型实例
   */
  public function relationModel(): Model
  {
    return $this->relationModel;
  }
}
