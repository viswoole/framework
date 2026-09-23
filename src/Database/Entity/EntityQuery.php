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

namespace Viswoole\Database\Entity;

use Generator;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Entity;
use Viswoole\Database\Exception\DataNotFoundException;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model\Query;
use Viswoole\Database\Raw;

/**
 * 实体查询构造器
 *
 * 扩展模型查询构造器，把各查询出口返回的数据行水合为实体实例：
 * find/first 返回实体、select/get 返回 {@see EntityCollection}、
 * cursor/chunk 逐条/逐批产出实体，create 返回已落库的实体。
 * 软删除、时间戳、修改器、with() 关联等 ORM 能力全部复用父类。
 *
 * 由于 PHP 返回型变限制（Entity 与 DataSet 无继承关系），覆写方法
 * 的声明返回类型保持父类不变，实体类型通过泛型注解提供给 IDE：
 * ```
 * // 泛型推导：$user 为 UserEntity（query() 为实例方法，静态查询直接链式调用）
 * $user = UserEntity::where('id', 1)->find();
 * ```
 *
 * @template TEntity of Entity
 * @extends Query
 * @see Entity
 */
class EntityQuery extends Query
{
  /**
   * {@inheritDoc}
   *
   * @param bool $allowEmpty 是否允许空结果
   * @return TEntity|Raw|null 单条实体；查询为空且允许空时返回 null
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function first(bool $allowEmpty = true): DataSet|Entity|Raw|null
  {
    return $this->find(null, $allowEmpty);
  }

  /**
   * {@inheritDoc}
   *
   * @param int|string|null $value 主键值，为 null 时需配合 where 条件
   * @param bool $allowEmpty 是否允许空结果，为 false 且结果为空时抛出异常
   * @return TEntity|Raw|null 单条实体；查询为空且允许空时返回 null
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function find(
    int|string|null $value = null,
    bool            $allowEmpty = true
  ): DataSet|Entity|Raw|null
  {
    // 逻辑完全复用基类：空结果返回 null、命中行走 newRowSet 水合出口
    // （本类覆写后返回实体），此处仅为提供 TEntity 泛型推导
    /** @var TEntity|Raw|null */
    return parent::find($value, $allowEmpty);
  }

  /**
   * {@inheritDoc}
   *
   * @param bool $allowEmpty 是否允许空结果
   * @return EntityCollection<TEntity>|Raw 实体集合
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function select(bool $allowEmpty = true): BaseCollection|Raw
  {
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    if (empty($result) && !$allowEmpty) {
      throw new DataNotFoundException('未查询到数据', 0, $this->getLastQuery()->sql->toString());
    }
    return $this->newRowsCollection($result);
  }

  /**
   * {@inheritDoc}
   *
   * @param array<int,array<string,mixed>> $rows 行数据列表
   * @return EntityCollection<TEntity> 实体集合
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   */
  #[Override]
  public function newRowsCollection(array $rows = []): BaseCollection
  {
    /** @var EntityCollection<TEntity> */
    return new EntityCollection($this, $rows);
  }

  /**
   * {@inheritDoc}
   *
   * 创建写入由父级完成：父级 create 经行级水合出口（newRowSet）返回的
   * 已是含主键、带变更快照的实体实例，无需二次转换。
   *
   * @param array<string,mixed> $data 关联数组数据
   * @param array<int,string> $columns 仅允许写入的列名，为空时不限制
   * @return TEntity 含主键的实体实例
   * @throws InvalidArgumentException 数据非关联数组，或实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function create(array $data, array $columns = []): DataSet|Entity
  {
    /** @var TEntity */
    return parent::create($data, $columns);
  }

  /**
   * {@inheritDoc}
   *
   * @return Generator<int,TEntity> 逐条产出实体
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function cursor(): Generator
  {
    foreach (parent::cursor() as $rowSet) {
      yield $this->toEntity($rowSet->getArrayCopy());
    }
  }

  /**
   * 将行数据水合为实体实例
   *
   * @param array<string,mixed> $row 行数据（键为蛇形列名）
   * @return TEntity 实体实例
   * @throws InvalidArgumentException 数据值与属性声明类型不匹配时抛出
   */
  public function toEntity(array $row): Entity
  {
    /** @var Entity $class */
    $class = get_class($this->model);
    /** @var TEntity */
    return $class::fromRow($row);
  }

  /**
   * {@inheritDoc}
   *
   * @return Generator<int,EntityCollection<TEntity>> 逐批产出实体集合
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function chunk(int $size): Generator
  {
    foreach (parent::chunk($size) as $collection) {
      // 父级产出的是 DataSet 集合，取原始行数组重新水合为实体集合
      $rows = array_map(
        static fn($rowSet) => $rowSet->getArrayCopy(),
        $collection->getArrayCopy()
      );
      yield $this->newRowsCollection($rows);
    }
  }

  /**
   * {@inheritDoc}
   *
   * 实体查询的行级水合出口：关联数据合并时以实体承载本模型行。
   *
   * @param array<string,mixed> $row 行数据
   * @return TEntity 实体实例
   * @throws InvalidArgumentException 实体水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   */
  #[Override]
  public function newRowSet(array $row): DataSet|Entity
  {
    return $this->toEntity($row);
  }
}
