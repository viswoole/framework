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
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model;
use Viswoole\Database\Raw;

/**
 * 中间表交互能力
 *
 * 为 BelongsToMany 提供中间表的条件过滤与读写操作，职责收敛：
 * - wherePivot()：读侧按绑定条件过滤中间表（配合 with() 使用）；
 * - attach()：新增绑定（幂等，已存在的绑定自动跳过）；
 * - detach()：解除绑定（可指定关联键，为 null 时解除全部）；
 * - sync()：以目标集合为准同步绑定（多退少补）。
 *
 * 依赖宿主（BelongsToMany）的成员：
 * @property-read Model $pivotModel 中间表模型实例
 * @property-read string $foreignPivotKey 中间表指向当前模型的外键名
 * @property-read string $relatedPivotKey 中间表指向关联模型的外键名
 * @method int|string resolveParentKey(int|string|DataSet $parent) 解析主表键值（定义于 RelationQuery）
 *
 * @see BelongsToMany
 */
trait InteractsWithPivot
{
  /** @var array<int,array{column:string,operator:string|int|float|array,value:string|int|float|array|null,connector:string}> 中间表附加条件列表 */
  protected array $pivotWheres = [];

  /**
   * 添加中间表查询条件，在中间表查询上追加 WHERE（作用于绑定关系本身）
   *
   * 与 handle() 的区别：handle() 过滤的是关联模型数据（如角色被禁用），
   * wherePivot() 过滤的是绑定关系（如仅取启用状态的绑定）。
   * 条件仅作用于读侧（with() 关联查询），不影响 attach()/detach() 的写入范围。
   * 参数语义与 Query::where() 完全一致，支持两参简写（省略运算符时
   * 默认 = 或 IN）、IS NULL 表达式与 AND/OR 连接符。
   * ```
   * // 仅取 2026-01-05 之后建立的绑定
   * return $this->belongsToMany(RoleModel::class, 'role_user', 'user_id', 'role_id')
   *   ->wherePivot('bind_time', '>=', '2026-01-05');
   * // 简写形式：数组自动转 IN
   * ->wherePivot('status', [1, 2]);
   * ```
   *
   * @param string $column 中间表列名
   * @param string|int|float|array $operator 运算符；当 $value 为 null 时作为比较值
   * @param string|int|float|array|null $value 比较值，为 null 时 $operator 作为值
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 运算符或连接符无效时抛出（由 Query::where() 校验）
   */
  public function wherePivot(
    string                      $column,
    string|int|float|array      $operator,
    string|int|float|array|null $value = null,
    string                      $connector = 'AND'
  ): static
  {
    // 只记录不校验：延迟到查询构建时转发给 Query::where()，复用其白名单校验
    $this->pivotWheres[] = compact('column', 'operator', 'value', 'connector');
    return $this;
  }

  /**
   * 新增绑定：将关联数据绑定到主数据（写入中间表）
   *
   * 幂等操作：已存在的绑定自动跳过，仅写入缺失的绑定；
   * $pivotData 会合并进每条新绑定行（如绑定时间、状态等扩展字段）。
   * 多条新绑定通过一条批量 INSERT 写入。
   * ```
   * // 为 1 号用户绑定角色 10、11，绑定时间取当前时间
   * $user->roles()->attach(1, [10, 11], ['bind_time' => date('Y-m-d H:i:s')]);
   * // 单个关联键可直接传标量；$parent 也可传主表行数据集
   * $user->roles()->attach($user, 10);
   * ```
   *
   * @param int|string|DataSet $parent 主表键值或主表行数据集
   * @param array|int|string $related 关联键或关联键数组
   * @param array $pivotData 附加的中间表字段，为空时不附加
   * @return int 实际新增的绑定数
   * @throws InvalidArgumentException 关联键为空或数据集中缺少主表键时抛出
   * @throws DbException 数据库操作异常
   */
  public function attach(
    int|string|DataSet $parent,
    array|int|string   $related,
    array              $pivotData = []
  ): int
  {
    $parentKey = $this->resolveParentKey($parent);
    $relatedList = $this->normalizeRelatedKeys($related);
    if ($relatedList === []) {
      throw new InvalidArgumentException('attach() 的关联键不能为空');
    }
    // 先查出已存在的绑定，跳过后写入，避免唯一约束冲突或重复绑定
    $existingRows = $this->pivotModel->query
      ->where($this->foreignPivotKey, '=', $parentKey)
      ->whereIn($this->relatedPivotKey, $relatedList)
      ->getArray();
    $existingKeys = array_column($existingRows, $this->relatedPivotKey);
    $newRows = [];
    foreach ($relatedList as $relatedKey) {
      if (in_array($relatedKey, $existingKeys, true)) continue;
      $newRows[] = array_merge(
        [$this->foreignPivotKey => $parentKey, $this->relatedPivotKey => $relatedKey],
        $pivotData
      );
    }
    if ($newRows === []) return 0;
    $result = $this->pivotModel->query->insert($newRows);
    return $result instanceof Raw ? 0 : (int)$result;
  }

  /**
   * 规范化关联键入参：标量转列表，去 null、去重并重建索引
   *
   * @param array|int|string $related 关联键或关联键数组
   * @return array<int,int|string> 规范化后的关联键列表
   */
  private function normalizeRelatedKeys(array|int|string $related): array
  {
    $list = is_array($related) ? $related : [$related];
    return array_values(array_unique(array_filter($list, fn($v) => $v !== null)));
  }

  /**
   * 解除绑定：删除中间表绑定记录
   * ```
   * // 解除 1 号用户与角色 10 的绑定（标量或数组均可）
   * $user->roles()->detach(1, 10);
   * $user->roles()->detach(1, [10, 11]);
   * // 解除 1 号用户的全部绑定
   * $user->roles()->detach(1);
   * ```
   *
   * @param int|string|DataSet $parent 主表键值或主表行数据集
   * @param array|int|string|null $related 关联键或关联键数组，为 null 时解除全部绑定
   * @return int 实际删除的绑定数
   * @throws InvalidArgumentException 数据集中缺少主表键时抛出
   * @throws DbException 数据库操作异常
   */
  public function detach(
    int|string|DataSet    $parent,
    array|int|string|null $related = null
  ): int
  {
    $parentKey = $this->resolveParentKey($parent);
    $query = $this->pivotModel->query->where($this->foreignPivotKey, '=', $parentKey);
    if ($related !== null) {
      $relatedList = $this->normalizeRelatedKeys($related);
      // 空列表无可删除的绑定，直接返回 0（避免构造空 IN 条件异常）
      if ($relatedList === []) return 0;
      $query->whereIn($this->relatedPivotKey, $relatedList);
    }
    $result = $query->delete();
    return $result instanceof Raw ? 0 : (int)$result;
  }

  /**
   * 同步绑定：以目标关联键集合为准，新增缺失绑定并移除多余绑定（多退少补）
   *
   * 一次调用完成对齐：目标集合中未绑定的关联键被批量写入，
   * 已有但不在目标集合中的绑定被批量移除，交集部分保持不变。
   * 传空数组表示清空该父级的全部绑定（等价 detach($parent)）。
   * $pivotData 仅合并进新增的绑定行，既有绑定不受影响。
   * ```
   * // 将 1 号用户的角色对齐为 10、20：解绑 11，新增 20
   * $result = $user->roles()->sync(1, [10, 20], ['bind_time' => '2026-03-01']);
   * // ['attached' => [20], 'detached' => [11]]
   * ```
   *
   * @param int|string|DataSet $parent 主表键值或主表行数据集
   * @param array|int|string $related 目标关联键或关联键数组
   * @param array $pivotData 附加到新增绑定的中间表字段
   * @return array{attached:array<int,int|string>,detached:array<int,int|string>} 新增与移除的关联键列表
   * @throws InvalidArgumentException 数据集中缺少主表键时抛出
   * @throws DbException 数据库操作异常
   */
  public function sync(
    int|string|DataSet $parent,
    array|int|string   $related,
    array              $pivotData = []
  ): array
  {
    $parentKey = $this->resolveParentKey($parent);
    $targetKeys = $this->normalizeRelatedKeys($related);
    $existingKeys = $this->fetchExistingPivotKeys($parentKey);
    // 多退：已有但不在目标集合中的绑定将被移除
    $detachedKeys = array_values(array_diff($existingKeys, $targetKeys));
    // 少补：目标集合中尚未绑定的关联键将被新增
    $attachedKeys = array_values(array_diff($targetKeys, $existingKeys));
    if ($attachedKeys !== []) {
      $rows = [];
      foreach ($attachedKeys as $relatedKey) {
        $rows[] = array_merge(
          [$this->foreignPivotKey => $parentKey, $this->relatedPivotKey => $relatedKey],
          $pivotData
        );
      }
      $this->pivotModel->query->insert($rows);
    }
    if ($detachedKeys !== []) {
      $this->pivotModel->query
        ->where($this->foreignPivotKey, '=', $parentKey)
        ->whereIn($this->relatedPivotKey, $detachedKeys)
        ->delete();
    }
    return ['attached' => $attachedKeys, 'detached' => $detachedKeys];
  }

  /**
   * 查询父级当前全部绑定的关联键
   *
   * @param int|string $parentKey 主表键值
   * @return array<int,int|string> 已绑定的关联键列表
   * @throws DbException 数据库操作异常
   */
  private function fetchExistingPivotKeys(int|string $parentKey): array
  {
    $rows = $this->pivotModel->query
      ->where($this->foreignPivotKey, '=', $parentKey)
      ->getArray();
    return array_values(
      array_filter(array_column($rows, $this->relatedPivotKey), fn($v) => $v !== null)
    );
  }

  /**
   * 将已记录的中间表条件应用到查询实例
   *
   * 运算符白名单与连接符校验由 Query::where() 内部完成。
   *
   * @param Query $query 中间表查询实例
   */
  protected function applyPivotWheres(Query $query): void
  {
    foreach ($this->pivotWheres as $condition) {
      $query->where(
        $condition['column'], $condition['operator'], $condition['value'], $condition['connector']
      );
    }
  }
}
