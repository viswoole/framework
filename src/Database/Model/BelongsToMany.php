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

namespace Viswoole\Database\Model;

use InvalidArgumentException;
use Viswoole\Database\Collection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model;
use Viswoole\Database\Raw;

/**
 * 多对多关联查询
 *
 * 通过中间表（pivot）关联两个模型，例如 users 与 roles 通过 role_user 表关联。
 * 读路径查询分两步执行：
 * 1. 以主表键集合查询中间表（可经 wherePivot() 追加绑定条件），
 *    构建 主表键 => [关联键 => 中间表行] 映射；
 * 2. 以去重后的关联键集合查询关联模型（可经 handle() 追加条件），
 *    再按中间表映射组装出各主表键的 Collection，每条关联数据
 *    附带 PIVOT_KEY 键保存对应的中间表行（可读取绑定时间等扩展字段）。
 * 写路径由 InteractsWithPivot 提供：attach() 新增绑定、detach() 解除绑定。
 *
 * 注意：与 RelationQuery 一致，本类只计算"主表键 => 关联集合"映射，
 * 不修改主数据本身；与主数据的合并由 Query::mergeRelationData 统一完成，
 * 天然复用其协程并发与串行降级两套执行机制。
 *
 * @see Model::belongsToMany()
 * @see RelationQuery
 * @see InteractsWithPivot
 */
class BelongsToMany extends RelationQuery
{
  use InteractsWithPivot;

  /** @var string 关联数据中携带中间表行的字段名 */
  public const string PIVOT_KEY = 'pivot';

  /**
   * @param Model $relationModel 关联模型实例
   * @param Model $pivotModel 中间表模型实例
   * @param string $foreignPivotKey 中间表中指向当前模型的外键名
   * @param string $relatedPivotKey 中间表中指向关联模型的外键名
   * @param string $localKey 当前模型的关联键名（通常为主键）
   * @param string $relatedKey 关联模型的关联键名（通常为主键）
   */
  public function __construct(
    Model            $relationModel,
    protected Model  $pivotModel,
    protected string $foreignPivotKey,
    protected string $relatedPivotKey,
    string           $localKey,
    protected string $relatedKey
  )
  {
    // 复用父类元信息：多对多对主模型而言恒为"一对多"形态，
    // foreignKey 槽位存放中间表指向当前模型的外键
    parent::__construct($relationModel, $foreignPivotKey, $localKey, true);
  }

  /**
   * 查询多对多关联数据，构建主表键到关联集合的映射
   *
   * @param array $data 主表查询结果（仅读取其中的 localKey 列）
   * @return array<mixed,Collection> 主表键 => 关联数据集合
   * @throws DbException 数据库操作失败时抛出
   */
  public function query(array $data): array
  {
    $keys = array_column($data, $this->localKey);
    // 过滤 null 并去重：null 参与 IN 永假且占位，重复键导致无谓的巨型 IN 列表
    $keys = array_values(array_unique(array_filter($keys, fn($v) => $v !== null)));
    // 主数据无有效键时无需查询，由调用方为每行填充空集合
    if ($keys === []) return [];
    $pivotMap = $this->fetchPivotMap($keys);
    if ($pivotMap === []) return [];
    $relatedMap = $this->fetchRelatedMap($pivotMap);
    if ($relatedMap === []) return [];
    return $this->assembleKeyMap($pivotMap, $relatedMap);
  }

  /**
   * 查询中间表，构建 主表键 => [关联键 => 中间表行] 映射
   *
   * @param array $keys 主表键集合
   * @return array<mixed,array<mixed,array>> 主表键 => (关联键 => 中间表行)
   * @throws DbException 数据库操作失败时抛出
   */
  private function fetchPivotMap(array $keys): array
  {
    $query = $this->pivotModel->query->whereIn($this->foreignPivotKey, $keys);
    // 应用 wherePivot() 记录的中间表条件
    $this->applyPivotWheres($query);
    $rows = $query->getArray();
    $map = [];
    foreach ($rows as $row) {
      $local = $row[$this->foreignPivotKey] ?? null;
      $related = $row[$this->relatedPivotKey] ?? null;
      // 任一外键为 NULL 的脏数据无法建立映射，跳过
      if ($local === null || $related === null) continue;
      // 同一绑定关系重复出现时仅保留最后一条（正常唯一约束下不会发生）
      $map[$local][$related] = $row;
    }
    return $map;
  }

  /**
   * 查询关联模型，构建 关联键 => 行数据 映射
   *
   * @param array<array<mixed,array>> $pivotMap 中间表映射
   * @return array<mixed,array> 关联键 => 关联模型行数据
   * @throws DbException 数据库操作失败时抛出
   */
  private function fetchRelatedMap(array $pivotMap): array
  {
    // 关联键去重：多个主行可共享同一条关联数据，IN 列表只查一次
    $relatedIds = [];
    foreach ($pivotMap as $relatedRows) {
      foreach ($relatedRows as $related => $pivotRow) $relatedIds[$related] = true;
    }
    if ($relatedIds === []) return [];
    $query = $this->relationModel->query->whereIn($this->relatedKey, array_keys($relatedIds));
    // 交给处理回调，对关联查询追加排序、过滤等额外条件
    if (isset($this->handle)) {
      call_user_func($this->handle, $query);
    }
    $map = [];
    foreach ($query->getArray() as $row) {
      // 缺少关联键的行无法回填映射，跳过
      if (!array_key_exists($this->relatedKey, $row)) continue;
      $map[$row[$this->relatedKey]] = $row;
    }
    return $map;
  }

  /**
   * 按中间表映射组装 主表键 => Collection 映射
   *
   * @param array<array<mixed,array>> $pivotMap 中间表映射
   * @param array<mixed,array> $relatedMap 关联模型行映射
   * @return array<mixed,Collection> 主表键 => 关联数据集合
   * @throws DbException
   */
  private function assembleKeyMap(array $pivotMap, array $relatedMap): array
  {
    $keyMapData = [];
    foreach ($pivotMap as $local => $relatedRows) {
      foreach ($relatedRows as $related => $pivotRow) {
        // 中间表指向的数据可能已被删除或被 handle 过滤，未查到则跳过该绑定
        if (!array_key_exists($related, $relatedMap)) continue;
        // 数组按值拷贝：同一关联行被多个主行共享时，各自的 pivot 数据互不影响
        $row = $relatedMap[$related];
        $row[self::PIVOT_KEY] = $pivotRow;
        if (!isset($keyMapData[$local])) {
          $keyMapData[$local] = new Collection($this->relationModel->query, []);
        }
        $keyMapData[$local]->append(
          new DataSet($this->relationModel->query->newQuery(), $row)
        );
      }
    }
    return $keyMapData;
  }

  /**
   * 多对多不支持 create()：继承实现会把中间表外键错误写入关联表
   *
   * 新增绑定请使用 attach()。
   *
   * @param int|string|DataSet $parent 主表键值或主表行数据集
   * @param array $data 关联表数据
   * @param array $columns 仅允许写入的列名
   * @return DataSet 永不返回
   * @throws InvalidArgumentException 始终抛出
   */
  #[\Override]
  public function create(int|string|DataSet $parent, array $data, array $columns = []): DataSet
  {
    throw new InvalidArgumentException('多对多关联不支持 create()，请使用 attach() 新增绑定');
  }

  /**
   * 多对多不支持 delete()：继承实现会按外键错误删除关联表数据
   *
   * 解除绑定请使用 detach()。
   *
   * @param int|string|DataSet $parent 主表键值或主表行数据集
   * @param bool $real 是否硬删除
   * @return int|Raw 永不返回
   * @throws InvalidArgumentException 始终抛出
   */
  #[\Override]
  public function delete(int|string|DataSet $parent, bool $real = false): int|Raw
  {
    throw new InvalidArgumentException('多对多关联不支持 delete()，请使用 detach() 解除绑定');
  }

  /**
   * 获取中间表模型实例
   *
   * @return Model 中间表模型实例
   */
  public function pivotModel(): Model
  {
    return $this->pivotModel;
  }

  /**
   * 获取中间表中指向当前模型的外键名
   *
   * @return string 外键字段名
   */
  public function foreignPivotKey(): string
  {
    return $this->foreignPivotKey;
  }

  /**
   * 获取中间表中指向关联模型的外键名
   *
   * @return string 外键字段名
   */
  public function relatedPivotKey(): string
  {
    return $this->relatedPivotKey;
  }

  /**
   * 获取关联模型的关联键名
   *
   * @return string 关联键字段名
   */
  public function relatedKey(): string
  {
    return $this->relatedKey;
  }
}
