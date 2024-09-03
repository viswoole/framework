<?php /** @noinspection PhpUnused */
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

namespace Viswoole\Database;

use Generator;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Query\ModelQuery;
use Viswoole\Database\Query\RunInfo;

/**
 * 模型基类
 *
 * @method static DataSet create(array $data, array $columns = []) 创建一条数据，并返回数据集
 * @method static Raw|int delete(bool $real = false) 删除记录
 * @method static Raw|int restore(array|string|int|null $id = null) 恢复软删除的数据
 * @method static array getHiddenColumn() 获取隐藏字段
 * @method static ModelQuery|static withTrashed(bool $withTrashed = true) 查询结果中包含软删除的数据
 * @method static ModelQuery|static groupBy(array|string $columns) 对结果进行分组
 * @method static null|RunInfo getLastQuery() 获取最后一次查询
 * @method static ModelQuery|static having(string $column, string $operator, mixed $value, string $connector = 'AND') 添加 HAVING 子句到分组查询。
 * @method static ModelQuery|static orderBy(Raw|array|string $column, string $direction = 'asc') 对结果进行排序。
 * @method static Query page(int $page, int $pageSize) 分页查询
 * @method static ModelQuery limit(int $limit) 限制返回的结果数量。
 * @method static ModelQuery offset(int $offset) 设置结果的偏移量。
 * @method static ModelQuery union(Raw|string $query, string $type = 'UNION') 合并另一个查询结果。
 * @method static ModelQuery distinct(bool $flag = true) 设置查询结果是否返回唯一记录。
 * @method static ModelQuery force(string $index) 强制索引
 * @method static ModelQuery alias(string $alias) 表别名
 * @method static ModelQuery reset() 重置查询选项。
 * @method static ModelQuery columns(string $column) 选择要查询的列。
 * @method static string getPrimaryKey() 获取主键字段名
 * @method static ModelQuery withoutColumns(string $column) 排除字段
 * @method static ModelQuery cache(string $key, int $expiry = 0, null|string $tag = null) 自动写入缓存
 * @method static ModelQuery lockForUpdate() 锁定记录以进行更新。
 * @method static ModelQuery sharedLock() 共享锁定记录。
 * @method static ModelQuery toRaw() 返回Raw对象，不执行查询
 * @method static ModelQuery replace(bool $flag = true) 强制写入
 * @method static ModelQuery orWhere(string $column, array|string|int|float $operator, array|string|int|float|null $value = null) OR 查询条件
 * @method static ModelQuery where(string $column, array|string|int|float $operator, array|string|int|float|null $value = null, string $connector = 'AND') 查询条件
 * @method static ModelQuery wheres(array $wheres) 用数组批量设置查询条件
 * @method static ModelQuery andWhere(string $column, array|string|int|float $operator, array|string|int|float|null $value = null) 查询条件（AND）
 * @method static ModelQuery whereIn(string $column, array $value, string $connector = 'AND') 查询条件（IN）
 * @method static ModelQuery whereNotIn(string $column, array $value, string $connector = 'AND') 查询条件（NOT IN）
 * @method static ModelQuery whereNull(string $column, string $connector = 'AND') 查询条件（IS NULL）
 * @method static ModelQuery whereNotNull(string $column, string $connector = 'AND') 查询条件（IS NOT NULL）
 * @method static ModelQuery whereNotBetween(string $column, array $value, string $connector = 'AND') 查询条件（NOT BETWEEN）
 * @method static ModelQuery whereBetween(string $column, array $value, string $connector = 'AND') 查询条件（BETWEEN）
 * @method static ModelQuery whereGroup(array $wheres, string $connector = 'AND') 查询条件组，支持嵌套
 * @method static ModelQuery whereExists(string $sql, array $bindings = []) 查询条件（EXISTS）
 * @method static ModelQuery whereRaw(string $sql, array $bindings = []) 原生 where 查询sql
 * @method static ModelQuery whereNotExists(string $sql, array $bindings = []) 查询条件（NOT EXISTS）
 * @method static ModelQuery LeftJoin(string $table, string $localKey, string $operator, string $foreignKey) 关联查询（LEFT）
 * @method static ModelQuery join(string $table, string $localKey, string $operator, string $foreignKey, string $type = 'INNER') 关联查询
 * @method static ModelQuery rightJoin(string $table, string $localKey, string $operator, string $foreignKey) 关联查询（RIGHT）
 * @method static ModelQuery fullJoin(string $table, string $localKey, string $operator, string $foreignKey) 关联查询（FULL）
 * @method static Raw|int insert(array $data) 插入数据
 * @method static Raw|string|int insertGetId(array $data) 插入数据，返回主键值
 * @method static Raw|int update(array $data) 更新记录
 * @method static Raw|int count(string $column = '*') 计算指定列不能为null的记录总数
 * @method static mixed value(string $column) 返回某个字段的值
 * @method static Raw|string|int|float min(string $column) 获取最小值。
 * @method static Raw|string|int|float max(string $column) 获取最大值。
 * @method static Raw|int|float avg(string $column) 获取平均值。
 * @method static Raw|int|float sum(string $column) 获取总和。
 * @method static DataSet|Raw find(string|int|null $value = null, bool $allowEmpty = true) 查询单条记录
 * @method static Collection|Raw select(bool $allowEmpty = true) 执行查询，并返回查询结果
 * @method static Generator cursor() 游标查询
 * @method static Generator chunk(int $size) 分段查询
 */
abstract class Model
{
  /** @var ModelQuery 模型查询实例 */
  public readonly ModelQuery $query;
  /** @var array 隐藏字段，不对外暴露 */
  protected array $hidden = [];
  /** @var bool 是否启用软删除 */
  protected bool $enableSoftDelete = false;
  /** @var string 软删除字段 */
  protected string $softDeleteFieldName = 'delete_time';
  /** @var string 软删除字段类型 datetime|timestamp|date|int */
  protected string $softDeleteFieldType = 'datetime';
  /** @var string|int|null 软删除默认记录值 */
  protected null|string|int $softDeleteFieldDefaultValue = null;
  /** @var int 自动写入时间戳0关闭1写入创建时间，2写入更新时间，3写入创建和更新时间 */
  protected int $autoWriteTimestamp = 0;
  /** @var string 创建时间字段 */
  protected string $createTimeFieldName = 'create_time';
  /** @var string 创建时间字段类型：datetime|timestamp|date */
  protected string $createTimeFormatType = 'datetime';
  /** @var string 更新时间字段名称 */
  protected string $updateTimeFieldName = 'update_time';
  /** @var string 更新时间字段类型：datetime|timestamp|date|日期格式表达式 */
  protected string $updateTimeFormatType = 'datetime';
  /** @var string 自动去除类名后缀 */
  protected string $suffix = 'Model';
  /** @var string 完整表名 */
  protected string $table;
  /** @var string 表主键 */
  protected string $pk = 'id';
  /** @var string|null 数据库通道名称，为null则使用默认通道 */
  protected ?string $channelName = null;
  /** @var bool 自动写入主键 */
  protected bool $autoWritePk = false;

  public function __construct()
  {
    if (!isset($this->table)) {
      // 获取类名，不包含命名空间
      $className = substr(strrchr(get_called_class(), "\\"), 1);
      // 去除类名中的尾部的 "Model" 字符
      $className = preg_replace('/' . preg_quote($this->suffix, '/') . '$/', '', $className);
      // 转换为蛇形命名
      $className = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
      $this->table = $className;
    }
    $this->query = new ModelQuery($this);
  }

  /**
   * 静态方法转发调用
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public static function __callStatic(string $name, array $arguments)
  {
    return call_user_func_array([new static(), $name], $arguments);
  }

  /**
   * 获取查询器
   *
   * @return ModelQuery
   */
  public function query(): ModelQuery
  {
    return $this->query;
  }

  /**
   * 获取模型中的属性
   *
   * @param string $key
   * @return mixed
   */
  public function __properties(string $key): mixed
  {
    if (property_exists($this, $key)) {
      return $this->{$key};
    } else {
      return null;
    }
  }

  /**
   * 转发调用到查询器
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public function __call(string $name, array $arguments)
  {
    return call_user_func_array([$this->query, $name], $arguments);
  }
}
