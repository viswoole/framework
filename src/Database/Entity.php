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

/** @noinspection PhpUnused */
declare(strict_types=1);

namespace Viswoole\Database;

use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\Entity\EntityCollection;
use Viswoole\Database\Entity\EntityHydrator;
use Viswoole\Database\Entity\EntityQuery;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Model\Query;

/**
 * 实体模型基类
 *
 * 在 Model ORM 能力（软删除、时间戳自动写入、关联查询、修改器等）之上，
 * 用 PHP 类型化属性承载数据，实现精确的 IDE 类型推导：
 * ```
 * final class UserEntity extends Entity
 * {
 *   protected string $table = 'users';
 *   protected string $suffix = 'Entity';
 *
 *   public int $id;
 *   public string $userName;                       // 列 user_name
 *   public ?string $remark = null;                 // 可空列
 *   public ?DateTimeImmutable $createTime = null;  // datetime 列
 *   public StatusEnum $status;                     // int/string 回退枚举
 * }
 *
 * $user = UserEntity::where('id', 1)->find();       // ?UserEntity：未命中返回 null
 * $user?->userName;                                 // nullsafe 读取
 * $user->userName = 'new';
 * $user->save();                                    // 仅 UPDATE 变更列
 * ```
 *
 * 约定与边界：
 * - 实体类中声明的每一个（非静态）属性对应一个表列，属性名驼峰、
 *   列名蛇形，自动互转；未映射到属性的列（关联数据、pivot 等）
 *   存入附加数据，仅用于展示与关联访问，不参与写入；
 * - 类型强转：水合时数据库值按属性声明类型严格转换
 *   （int|float|string|bool|array|DateTimeInterface|BackedEnum），
 *   非可空属性遇到 NULL 抛出异常；
 * - 不支持 readonly 属性：水合与 save() 需要对属性赋值；
 * - 写入规则：save() 对已持久化实体做快照对比增量 UPDATE，对新实体
 *   做整体 INSERT（主键留空时回填自增值）；未初始化属性不参与写入；
 *   声明默认值且值仍等于默认值的属性同样不参与 INSERT，交由数据库
 *   列默认值生效；
 * - toArray() 输出列名键数组（蛇形），枚举转回退值、日期对象格式化
 *   为 'Y-m-d H:i:s'，并沿用 $hidden 过滤与 get{Field}Attr 获取器；
 * - 静态调用经 Model::__callStatic 转发到 EntityQuery，链式条件与
 *   Model 用法一致（如 UserEntity::where(...)->find()）；query() 为
 *   实例方法，需要查询实例时用 `(new UserEntity())->query()`，
 *   配合泛型注解 `->find()` 可推导出实体类本身。
 *
 * @method static EntityCollection<static>|Raw select(bool $allowEmpty = true) 执行查询并返回实体集合
 * @method static EntityCollection<static>|Raw get(bool $allowEmpty = true) 执行查询并返回实体集合
 * @method static static|Raw|null find(int|string|null $value = null, bool $allowEmpty = true) 按主键查询单条实体（空结果且允许空时返回 null）
 * @method static static|Raw|null first(bool $allowEmpty = true) 查询单条实体（空结果且允许空时返回 null）
 * @see EntityQuery
 * @see Model
 */
abstract class Entity extends Model implements JsonSerializable
{
  /** @var string 自动去除的类名后缀，用于从类名推断表名 */
  protected string $suffix = 'Entity';
  /** @var array<string,mixed> 水合时的原始值快照（列名 => 脱水归一化值），仅从数据库行水合时记录 */
  private array $original = [];
  /** @var bool 是否从数据库行水合而来（决定 save() 走插入还是增量更新） */
  private bool $isPersisted = false;
  /** @var array<string,mixed> 未映射到属性的附加数据（关联结果、pivot 等），不参与写入 */
  private array $extras = [];

  /**
   * 构建实体实例
   *
   * 传入行数据时立即水合：按属性声明类型强转并记录原始值快照
   * （后续 save() 以此做增量对比）；未传入时创建空白实体
   * （赋值属性后调用 save() 即插入）。
   *
   * @param array<string,mixed> $data 行数据（键为蛇形列名）
   * @throws InvalidArgumentException 数据值与属性声明类型不匹配时抛出
   */
  public function __construct(array $data = [])
  {
    parent::__construct();
    // 构造传参 ≠ 已持久化：数据仅填充属性，不记录快照，
    // save() 将走整体插入；数据库行水合须走 static::fromRow()
    if ($data !== []) $this->hydrateRow($data, false);
  }

  /**
   * 将行数据水合到实体属性
   *
   * @param array<string,mixed> $row 行数据（键为蛇形列名）
   * @param bool $persisted 是否来自数据库行：为 true 时记录原始值快照
   *   并标记已持久化（save 走增量更新），为 false 仅填充属性
   * @return void
   * @throws InvalidArgumentException 数据值与属性声明类型不匹配，或非可空属性遇 NULL 时抛出
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  private function hydrateRow(array $row, bool $persisted): void
  {
    $original = [];
    $extras = [];
    foreach ($row as $column => $value) {
      $meta = EntityHydrator::metaByColumn(static::class, $column);
      if ($meta === null) {
        // 未声明为属性的列（关联数据、pivot 等）存入附加数据
        $extras[$column] = $value;
        continue;
      }
      if ($value === null) {
        if (!$meta->isNullable) {
          throw new InvalidArgumentException(
            "列 $column 的值为 NULL，但属性 " . static::class . ":\$$meta->propertyName 不允许为空"
          );
        }
        $meta->prop->setValue($this, null);
        $original[$column] = null;
        continue;
      }
      $typed = EntityHydrator::coerce($meta, $value);
      $meta->prop->setValue($this, $typed);
      // 快照存脱水归一化值，与 save() 的对比基准保持同构
      $original[$column] = EntityHydrator::dehydrate($meta, $typed);
    }
    // 声明了默认值的属性（如 public ?string $remark = null）虽未出现在
    // 行数据中也纳入快照基线，使 updateDirty 仅将「水合后新赋值」的
    // 属性视为变更，避免默认值被误判为脏数据写入数据库
    foreach (EntityHydrator::metasByColumn(static::class) as $meta) {
      if (isset($original[$meta->column])) continue;
      if (!$meta->prop->isInitialized($this)) continue;
      $original[$meta->column] = EntityHydrator::dehydrate($meta, $meta->prop->getValue($this));
    }
    $this->original = $persisted ? $original : [];
    $this->isPersisted = $persisted;
    $this->extras = $extras;
  }

  /**
   * 按列名给实体属性赋值
   *
   * 供框架内部（集合批量更新同步等）按列名（蛇形）写入使用，
   * 值会按属性声明类型做与水合一致的强转。
   *
   * @param string $column 列名（蛇形）
   * @param mixed $value 列值
   * @return void
   * @throws InvalidArgumentException 列未声明为属性或值类型不匹配时抛出
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  public function setValue(string $column, mixed $value): void
  {
    $meta = EntityHydrator::metaByColumn(static::class, $column);
    if ($meta === null) {
      // 未声明为属性的列写入附加数据（与水合行为一致）
      if ($value === null) {
        unset($this->extras[$column]);
      } else {
        $this->extras[$column] = $value;
      }
      return;
    }
    if ($value === null) {
      if (!$meta->isNullable) {
        throw new InvalidArgumentException(
          "列 $column 的值为 NULL，但属性 " . static::class . ":\$$meta->propertyName 不允许为空"
        );
      }
      $meta->prop->setValue($this, null);
      return;
    }
    $meta->prop->setValue($this, EntityHydrator::coerce($meta, $value));
  }

  /**
   * 将属性值脱水为列名键数组（仅包含已初始化的属性）
   *
   * 附加数据不参与脱水：关联结果与 pivot 不应写入业务表。
   *
   * @return array<string,mixed> 列名 => 可绑定数据库的标量值
   */
  private function dehydrate(): array
  {
    $row = [];
    foreach (EntityHydrator::metasByColumn(static::class) as $meta) {
      if (!$meta->prop->isInitialized($this)) continue;
      $row[$meta->column] = EntityHydrator::dehydrate($meta, $meta->prop->getValue($this));
    }
    return $row;
  }

  /**
   * 读取实体中指定列的值
   *
   * 供关联查询、集合操作等框架内部按列名（蛇形）取值使用。
   *
   * @param string $column 列名（蛇形）
   * @return mixed 列值；列未声明为属性时返回附加数据，均不存在返回 null
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  public function getValue(string $column): mixed
  {
    $meta = EntityHydrator::metaByColumn(static::class, $column);
    if ($meta !== null) {
      return $meta->prop->isInitialized($this) ? $meta->prop->getValue($this) : null;
    }
    return $this->extras[$column] ?? null;
  }

  /**
   * 从数据库行数据构建已持久化实体（框架内部水合入口）
   *
   * 与构造传参的区别：记录原始值快照并标记已持久化，
   * 后续 save() 走快照对比的增量更新。
   *
   * @param array<string,mixed> $row 数据库行数据（键为蛇形列名）
   * @return static 已持久化的实体实例
   * @throws InvalidArgumentException 数据值与属性声明类型不匹配时抛出
   * @throws RuntimeException 属性声明不支持的字段类型或 readonly 时抛出
   */
  public static function fromRow(array $row): static
  {
    $entity = new static();
    $entity->hydrateRow($row, true);
    return $entity;
  }

  /**
   * 获取实体查询构造器实例
   *
   * 相比 Model::query() 返回值更精确（EntityQuery），配合泛型注解
   * `query()->find()` 可推导出实体类本身。
   *
   * @return EntityQuery<static> 实体查询构造器
   */
  public function query(): EntityQuery
  {
    /** @var EntityQuery<static> $query */
    $query = $this->query;
    return $query;
  }

  /**
   * 将当前实体持久化到数据库
   *
   * - 未持久化实体（未经水合）：整体 INSERT；主键属性为空时走
   *   自增回填（insertGetId），已显式赋值时直接写入该主键值；
   * - 已持久化实体：以水合快照为基准做增量对比，仅 UPDATE 变更列；
   *   使用原始主键定位行，无变更时返回 false。
   *
   * @return bool 插入成功返回 true；更新时受影响行数为 1 返回 true，无变更返回 false
   * @throws InvalidArgumentException 主键回填值与属性声明类型不匹配时抛出
   * @throws RuntimeException 缺少主键字段，或属性声明不支持的字段类型/readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function save(): bool
  {
    $row = $this->dehydrate();
    if (!$this->isPersisted) return $this->insertNew($row);
    return $this->updateDirty($row);
  }

  /**
   * 新实体的插入落库
   *
   * 声明默认值且值仍等于默认值的属性不参与写入（视为未显式赋值，
   * 交由数据库列默认值生效），与「未初始化属性不参与写入」约定对齐，
   * 避免如 string 空串默认值误写 DATETIME 列触发严格模式错误。
   *
   * @param array<string,mixed> $row 脱水后的行数据
   * @return bool 插入成功返回 true
   * @throws InvalidArgumentException 实体无任何属性值时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  private function insertNew(array $row): bool
  {
    // 声明默认值且值仍等于默认值的列过滤：与脱水归一化后的默认值
    // 严格比较（元数据构建时已预脱水，枚举/日期默认值可直接比较）
    foreach (EntityHydrator::metasByColumn(static::class) as $meta) {
      if (!$meta->hasDeclaredDefault) continue;
      if (array_key_exists($meta->column, $row) && $row[$meta->column] === $meta->declaredDefault) {
        unset($row[$meta->column]);
      }
    }
    // 空数据守卫：无任何非 null 属性值的实体不允许落库——
    // 空数据（或仅有声明默认值的可空列）会生成只有 NULL 列的 INSERT，
    // MySQL 下会静默插入一条全默认值记录（垃圾数据），必须在此拦截
    if (!array_filter($row, static fn($v) => $v !== null)) {
      throw new InvalidArgumentException(
        '实体 ' . static::class . ' 无任何属性值，无法保存'
      );
    }
    $pkColumn = $this->pk;
    $pkValue = $row[$pkColumn] ?? null;
    // 与 Model::create 语义对齐：主键已显式传入（含雪花 ID）时直接 insert，
    // 空值（null/''/0/'0'）视为未传入，走自增回填
    $hasExplicitPk = $pkValue !== null && $pkValue !== '' && $pkValue !== 0 && $pkValue !== '0';
    if ($hasExplicitPk) {
      $this->query->strict(false)->insert($row);
    } else {
      unset($row[$pkColumn]);
      $id = $this->query->insertGetId($row);
      $meta = EntityHydrator::metaByColumn(static::class, $pkColumn);
      // 主键声明为实体属性时回填自增值
      if ($meta !== null && $id !== '') {
        $meta->prop->setValue($this, EntityHydrator::coerce($meta, $id));
      }
    }
    // 已落库：标记持久化并重置变更基准，后续 save 走增量更新
    $this->isPersisted = true;
    $this->markSynced();
    return true;
  }

  /**
   * 清空变更基准，将当前属性值标记为已同步
   *
   * save() 成功后调用，使后续增量对比以最新数据为基准。
   *
   * @return void
   */
  public function markSynced(): void
  {
    $this->original = $this->dehydrate();
  }

  /**
   * 已持久化实体的增量更新
   *
   * @param array<string,mixed> $row 脱水后的行数据
   * @return bool 受影响行数为 1 返回 true，无变更返回 false
   * @throws RuntimeException 缺少主键字段时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  private function updateDirty(array $row): bool
  {
    // 与快照不同视为变更；快照中不存在的列（水合后新赋值的属性）一并写入
    $dirty = array_filter($row, function ($value, $column) {
      return !array_key_exists($column, $this->original) || $value !== $this->original[$column];
    }, ARRAY_FILTER_USE_BOTH);
    if ($dirty === []) return false;
    $pkColumn = $this->pk;
    // 用原始主键定位行：主键被业务修改时，新值进入 $dirty 一起更新
    $pkValue = $this->original[$pkColumn] ?? null;
    if ($pkValue === null) {
      throw new RuntimeException("保存数据失败，缺少主键字段($pkColumn)");
    }
    $count = $this->query->strict(false)->where($pkColumn, $pkValue)->update($dirty);
    $this->markSynced();
    return $count === 1;
  }

  /**
   * 删除当前实体对应的记录
   *
   * 绑定模型的软删除配置：启用软删除时执行软删除，传 $real = true 强制硬删除。
   *
   * @param bool $real 是否硬删除，默认 false
   * @return int|Raw 受影响的记录数
   * @throws RuntimeException 缺少主键字段时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function delete(bool $real = false): int|Raw
  {
    $pkColumn = $this->pk;
    $pkValue = $this->original[$pkColumn] ?? $this->getValue($pkColumn);
    if ($pkValue === null) {
      throw new RuntimeException("删除记录失败，缺少主键字段($pkColumn)");
    }
    return $this->query->where($pkColumn, $pkValue)->delete($real);
  }

  /**
   * JSON 序列化：走 toArray 的隐藏过滤与获取器约定
   *
   * @return array<string,mixed>
   */
  public function jsonSerialize(): array
  {
    return $this->toArray();
  }

  /**
   * 将实体转换为列名键数组
   *
   * 属性值脱水为可序列化标量（枚举 → 回退值、日期对象 → 'Y-m-d H:i:s'），
   * 附加数据（关联结果等）一并输出并递归转换；沿用 $hidden 隐藏过滤
   * 与 get{Field}Attr 获取器约定。
   *
   * @param bool $withAttr 是否应用获取器，默认 true
   * @param bool $hidden 是否过滤隐藏字段，默认 true
   * @param int $maxDepth 嵌套集合递归深度上限，默认 10
   * @return array<string,mixed> 列名 => 序列化安全的值
   */
  public function toArray(bool $withAttr = true, bool $hidden = true, int $maxDepth = 10): array
  {
    $array = [];
    // 附加键不与字段键冲突：属性列名与附加键（关联名）天然不重叠
    $rows = $this->dehydrate() + $this->extras;
    foreach ($rows as $key => $value) {
      if ($hidden && in_array($key, $this->hidden, true)) continue;
      if ($value instanceof BaseCollection) {
        // 关联数据为集合/数据集时递归转换，深度用尽置空防无限递归
        $value = $maxDepth > 0 ? $value->toArray($withAttr, $hidden, $maxDepth - 1) : [];
      } elseif ($withAttr) {
        $value = $this->query->withGetAttr($key, $value);
      }
      $array[$key] = $value;
    }
    return $array;
  }

  /**
   * 属性按声明直接读取；未映射到属性的键回退附加数据（关联结果等）。
   */
  public function __get(string $name): mixed
  {
    return $this->getValue($name);
  }

  public function __isset(string $name): bool
  {
    $meta = EntityHydrator::metaByProperty(static::class, $name);
    if ($meta !== null) {
      return $meta->prop->isInitialized($this) && $meta->prop->getValue($this) !== null;
    }
    return isset($this->extras[$name]) || parent::__isset($name);
  }

  /**
   * {@inheritDoc}
   *
   * 子类可重写以使用自定义查询类，但必须继承 EntityQuery。
   */
  protected function createQuery(): Query
  {
    return new EntityQuery($this);
  }
}
