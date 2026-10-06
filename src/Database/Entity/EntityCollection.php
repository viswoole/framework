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

use InvalidArgumentException;
use Override;
use RuntimeException;
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\Entity;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model\Query;
use Viswoole\Database\Raw;

/**
 * 实体集合
 *
 * 承载实体查询（EntityQuery）的多行结果，集合元素为实体实例。
 * 与 DataSet 系集合的差异：元素是强类型实体对象，数组操作
 * （追加/批量更新等）通过实体属性完成；迭代、JSON 序列化、
 * 隐藏字段过滤等基础能力复用 BaseCollection。
 *
 * @template TEntity of Entity
 * @extends BaseCollection<int,TEntity>
 * @see EntityQuery
 */
class EntityCollection extends BaseCollection
{
  /**
   * 构建实体集合，行数组自动水合为实体实例
   *
   * @param BaseQuery|Query $query 查询对象，必须绑定 EntityQuery
   * @param array<int,array<string,mixed>|Entity> $data 行数组或实体实例列表
   * @throws InvalidArgumentException 未绑定 EntityQuery 或元素类型不合法时抛出
   */
  public function __construct(BaseQuery|Query $query, array $data)
  {
    if (!$query instanceof EntityQuery) {
      throw new InvalidArgumentException('EntityCollection 必须绑定 EntityQuery 查询实例');
    }
    $items = [];
    foreach ($data as $item) {
      $items[] = $item instanceof Entity ? $item : $query->toEntity($item);
    }
    parent::__construct($query, $items);
  }

  /**
   * 将集合递归转换为数组，每个实体委托其 toArray 序列化
   *
   * @param bool $withAttr 是否应用获取器，默认 true
   * @param bool $hidden 是否过滤隐藏字段，默认 true
   * @param int $maxDepth 最大递归深度，默认 10，防止嵌套集合无限递归
   * @return array<int,mixed> 转换后的数组数据
   */
  #[Override]
  public function toArray(bool $withAttr = true, bool $hidden = true, int $maxDepth = 10): array
  {
    if ($maxDepth <= 0) return [];
    return array_map(function ($item) use ($maxDepth, $withAttr, $hidden) {
      return $item instanceof Entity
        ? $item->toArray($withAttr, $hidden, $maxDepth - 1)
        : $item;
    }, $this->getArrayCopy());
  }

  /**
   * 获取集合中第一个实体
   *
   * @return TEntity|null 第一个实体，集合为空时返回 null
   */
  public function first(): ?Entity
  {
    $items = $this->getArrayCopy();
    if ($items === []) return null;
    // array_key_first 语义：不假定键为连续 0 基整型（offsetSet 允许自定义键）
    return $items[array_key_first($items)];
  }

  /**
   * 获取集合中最后一个实体
   *
   * @return TEntity|null 最后一个实体，集合为空时返回 null
   */
  public function last(): ?Entity
  {
    $items = $this->getArrayCopy();
    if ($items === []) return null;
    return $items[array_key_last($items)];
  }

  /**
   * 获取全部实体实例
   *
   * @return array<int,TEntity> 实体数组
   */
  public function all(): array
  {
    return $this->getArrayCopy();
  }

  /**
   * 根据回调函数过滤集合中的元素
   *
   * @param callable $callback 过滤回调，接收实体参数，返回 true 时保留
   * @return static 过滤后的新集合
   */
  public function filter(callable $callback): static
  {
    return $this->cloneSelf(array_values(array_filter($this->getArrayCopy(), $callback)));
  }

  /**
   * 基于新数据克隆当前集合
   *
   * @param array<int,Entity> $items 实体列表
   * @return static 克隆后的集合实例
   */
  private function cloneSelf(array $items): static
  {
    return new static($this->query, $items);
  }

  /**
   * 对集合中每个实体应用回调，返回转换后的新集合
   *
   * @param callable $callback 回调，接收实体并必须返回实体实例
   * @return static 转换后的新集合
   * @throws InvalidArgumentException 回调返回非实体值时抛出
   */
  public function map(callable $callback): static
  {
    return $this->cloneSelf(array_map($callback, $this->getArrayCopy()));
  }

  /**
   * 批量更新集合中所有实体对应的记录
   *
   * 以相同的键值对更新全部主键匹配的行，并同步到内存中的实体
   * （重置变更基准）。
   *
   * @param array<string,mixed> $data 要更新的键值对（键为蛇形列名）
   * @return int 受影响的记录数
   * @throws InvalidArgumentException 更新值与实体属性声明类型不匹配时抛出
   * @throws RuntimeException 集合中存在缺少主键的实体时抛出
   * @throws DbException 数据库操作异常时抛出
   */
  public function update(array $data): int
  {
    $pkList = $this->collectPks();
    // 空集合没有可更新的记录，直接返回 0 而非构造空 IN 条件
    if ($pkList === []) return 0;
    /** @var EntityQuery $query */
    $query = $this->query;
    $result = $query->strict(false)->whereIn($query->getPrimaryKey(), $pkList)->update($data);
    // toRaw 场景 SQL 未真正执行：不做返回值换算，也不同步内存实体
    if ($result instanceof Raw) return 0;
    // 同步已落库数据到内存实体并重置变更基准
    $this->each(static function (Entity $entity) use ($data) {
      foreach ($data as $column => $value) {
        $entity->setValue($column, $value);
      }
      $entity->markSynced();
    });
    return (int)$result;
  }

  /**
   * 收集集合中全部实体的主键值
   *
   * @return array<int,int|string> 主键值列表
   * @throws RuntimeException 某实体缺少主键值时抛出
   */
  private function collectPks(): array
  {
    /** @var EntityQuery $query */
    $query = $this->query;
    $pk = $query->getPrimaryKey();
    $pkList = [];
    foreach ($this->getArrayCopy() as $index => $entity) {
      $value = $entity->getValue($pk);
      if (is_int($value) || is_string($value)) {
        $pkList[] = $value;
      } else {
        throw new RuntimeException(
          "操作失败，集合第 $index 个实体缺少主键($pk)"
        );
      }
    }
    return $pkList;
  }

  /**
   * 遍历集合中的每个实体
   *
   * @param callable $callback 回调，接收实体参数
   * @return void
   */
  public function each(callable $callback): void
  {
    foreach ($this->getArrayCopy() as $item) {
      $callback($item);
    }
  }

  /**
   * {@inheritDoc}
   *
   * 批量删除集合中所有实体对应的记录（按主键 IN 匹配），
   * 关联模型启用软删除时默认软删除。
   *
   * @param bool $real 是否硬删除，默认 false
   * @return int 成功删除的记录数
   * @throws RuntimeException 集合中存在缺少主键的实体时抛出
   * @throws DbException 数据库操作异常时抛出
   */
  #[Override]
  public function delete(bool $real = false): int
  {
    /** @var EntityQuery $query */
    $query = $this->query;
    $pkList = $this->collectPks();
    // 空集合没有可删除的记录，直接返回 0 而非构造空 IN 条件
    if ($pkList === []) return 0;
    $result = $query->whereIn($query->getPrimaryKey(), $pkList)->delete($real);
    return $result instanceof Raw ? 0 : (int)$result;
  }

  /**
   * 通过数组方式添加元素，仅接受实体实例
   *
   * @param mixed $key 键名
   * @param mixed $value 实体实例
   * @throws InvalidArgumentException 值不是实体实例时抛出
   */
  #[Override]
  public function offsetSet(mixed $key, mixed $value): void
  {
    if (!$value instanceof Entity) {
      throw new InvalidArgumentException('实体集合中的元素必须是 Entity 实例，行数组请先经查询水合');
    }
    parent::offsetSet($key, $value);
  }
}
