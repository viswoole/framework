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
use Viswoole\Database\Model\Query;
use Viswoole\Database\Model\RelationQuery;
use Viswoole\Database\Query\RunInfo;

/**
 * 数据模型基类
 *
 * 提供ORM核心能力：自动推断表名、软删除、时间戳自动写入、关联查询、获取器等。
 * 子类通过定义属性来声明表名、主键、软删除字段等元信息，框架自动处理查询与写入逻辑。
 * 静态方法通过 __callStatic 转发到 Query 实例，支持链式调用。
 *
 * @method static DataSet create(array $data, array $columns = []) 创建一条数据，并返回数据集
 * @method static Raw|int delete(bool $real = false) 删除记录
 * @method static Raw|int restore(array|string|int|null $id = null) 恢复软删除的数据
 * @method static array getHiddenColumn() 获取隐藏字段
 * @method static Query withTrashed(bool $withTrashed = true) 查询结果中包含软删除的数据
 * @method static Query groupBy(array|string $columns) 对结果进行分组
 * @method static null|RunInfo getLastQuery() 获取最后一次查询
 * @method static Query having(string $column, string $operator, mixed $value, string $connector = 'AND') 添加 HAVING 子句到分组查询。
 * @method static Query orderBy(Raw|array|string $column, string $direction = 'asc') 对结果进行排序。
 * @method static Query page(int $page, int $pageSize) 分页查询
 * @method static Query limit(int $limit) 限制返回的结果数量。
 * @method static Query offset(int $offset) 设置结果的偏移量。
 * @method static Query union(Raw|string $query, string $type = 'UNION') 合并另一个查询结果。
 * @method static Query distinct(bool $flag = true) 设置查询结果是否返回唯一记录。
 * @method static Query force(string $index) 强制索引
 * @method static Query alias(string $alias) 表别名
 * @method static Query reset() 重置查询选项。
 * @method static Query columns(string $column) 选择要查询的列。
 * @method static string getPrimaryKey() 获取主键字段名
 * @method static string getTableName() 获取表名。
 * @method static Query with(array|string $relation) 关联查询。
 * @method static Query withoutColumns(string $column) 排除字段
 * @method static Query cache(string $key, int $expiry = 0, null|string $tag = null) 自动写入缓存
 * @method static Query lockForUpdate() 锁定记录以进行更新。
 * @method static Query sharedLock() 共享锁定记录。
 * @method static Query toRaw() 返回Raw对象，不执行查询
 * @method static Query replace(bool $flag = true) 强制写入
 * @method static Query orWhere(string $column, array|string|int|float $operator, array|string|int|float|null $value = null) OR 查询条件
 * @method static Query where(string $column, array|string|int|float $operator, array|string|int|float|null $value = null, string $connector = 'AND') 查询条件
 * @method static Query wheres(array $wheres) 用数组批量设置查询条件
 * @method static Query andWhere(string $column, array|string|int|float $operator, array|string|int|float|null $value = null) 查询条件（AND）
 * @method static Query whereIn(string $column, array $value, string $connector = 'AND') 查询条件（IN）
 * @method static Query whereNotIn(string $column, array $value, string $connector = 'AND') 查询条件（NOT IN）
 * @method static Query whereNull(string $column, string $connector = 'AND') 查询条件（IS NULL）
 * @method static Query whereNotNull(string $column, string $connector = 'AND') 查询条件（IS NOT NULL）
 * @method static Query whereNotBetween(string $column, array $value, string $connector = 'AND') 查询条件（NOT BETWEEN）
 * @method static Query whereBetween(string $column, array $value, string $connector = 'AND') 查询条件（BETWEEN）
 * @method static Query whereGroup(array $wheres, string $connector = 'AND') 查询条件组，支持嵌套
 * @method static Query whereExists(string $sql, array $bindings = []) 查询条件（EXISTS）
 * @method static Query whereRaw(string $sql, array $bindings = []) 原生 where 查询sql
 * @method static Query whereNotExists(string $sql, array $bindings = []) 查询条件（NOT EXISTS）
 * @method static Query LeftJoin(string $table, string $localKey, string $operator, string $foreignKey) 关联查询（LEFT）
 * @method static Query join(string $table, string $localKey, string $operator, string $foreignKey, string $type = 'INNER') 关联查询
 * @method static Query rightJoin(string $table, string $localKey, string $operator, string $foreignKey) 关联查询（RIGHT）
 * @method static Query fullJoin(string $table, string $localKey, string $operator, string $foreignKey) 关联查询（FULL）
 * @method static Raw|int insert(array $data) 插入数据
 * @method static Raw|string|int insertGetId(array $data) 插入数据，返回主键值
 * @method static Raw|int update(array $data) 更新记录
 * @method static Raw|int count(string $column = '*') 计算指定列不能为null的记录总数
 * @method static mixed value(string $column) 返回某个字段的值，未查询到数据返回false
 * @method static Raw|string|int|float min(string $column) 获取最小值。
 * @method static Raw|string|int|float max(string $column) 获取最大值。
 * @method static Raw|int|float avg(string $column) 获取平均值。
 * @method static Raw|int|float sum(string $column) 获取总和。
 * @method static DataSet|Raw find(string|int|null $value = null, bool $allowEmpty = true) 查询单条记录
 * @method static DataSet|Raw first(bool $allowEmpty = true) 查询单条记录
 * @method static Collection|Raw select(bool $allowEmpty = true) 执行查询，并返回查询结果
 * @method static Collection|Raw get(bool $allowEmpty = true) 执行查询，并返回查询结果
 * @method static array|Raw getArray() 执行查询，并以数组方式返回查询结果
 * @method static Generator cursor() 游标查询
 * @method static Query strict(bool $flag = true) 如果关闭严格模式，则会忽略写入不存在的字段。
 * @see Query
 */
abstract class Model
{
  /** @var Query 模型查询实例，通过该实例执行所有数据库操作 */
  public readonly Query $query;
  /** @var array 对外输出时需隐藏的字段名列表 */
  protected array $hidden = [];
  /** @var bool 是否启用软删除功能 */
  protected bool $enableSoftDelete = false;
  /** @var string 软删除标记字段名 */
  protected string $softDeleteFieldName = 'delete_time';
  /** @var string 软删除字段类型 datetime|timestamp|date|int */
  protected string $softDeleteFieldType = 'datetime';
  /** @var string|int|null 软删除字段的默认值（未删除状态），null 表示字段为NULL时未删除 */
  protected null|string|int $softDeleteFieldDefaultValue = null;
  /** @var int 自动写入时间戳模式：0=关闭，1=仅创建时间，2=仅更新时间，3=创建和更新时间 */
  protected int $autoWriteTimestamp = 0;
  /** @var string 创建时间字段名 */
  protected string $createTimeFieldName = 'create_time';
  /** @var string 创建时间字段类型 datetime|timestamp|date */
  protected string $createTimeFormatType = 'datetime';
  /** @var string 更新时间字段名 */
  protected string $updateTimeFieldName = 'update_time';
  /** @var string 更新时间字段类型 datetime|timestamp|date|日期格式表达式 */
  protected string $updateTimeFormatType = 'datetime';
  /** @var string 自动去除的类名后缀，用于从类名推断表名 */
  protected string $suffix = 'Model';
  /** @var string 完整表名，未设置时由类名自动推断 */
  protected string $table;
  /** @var string 表主键字段名 */
  protected string $pk = 'id';
  /** @var string|null 数据库通道名称，为 null 时使用默认通道 */
  protected ?string $channelName = null;
  /** @var bool 是否自动生成主键值并写入 */
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
    $this->query = $this->createQuery();
  }

  /**
   * 创建模型查询构造器实例
   *
   * 子类可重写此方法以使用自定义的 Query 类。
   *
   * @return Query 模型查询构造器
   */
  protected function createQuery(): Query
  {
    return new Query($this);
  }

  /**
   * 将静态方法调用转发到 Query 实例，实现 Model::where() 等链式调用
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed Query 方法的返回值
   */
  public static function __callStatic(string $name, array $arguments)
  {
    return call_user_func_array([(new static())->query, $name], $arguments);
  }

  /**
   * 获取查询器实例，用于 IDE 智能提示的动态调用入口
   *
   * 示例：
   * ```
   * // 实例化模型
   * $model = new UserModel();
   * // 直接动态调用select方法idea会提示：static 方法 'select' 不应被动态调用，但类具有 '__magic' 方法。
   * $collection = $model->select();
   * // 为了避免上述提示，可以使用以下方式调用。
   * $collection = $model->query()->select();
   * $collection = $model->query->select();
   * // 以上两种调用方式都可以实现动态调用，且带有IDE智能提示，我们也建议这样调用。
   * // 这样能有效减少了魔术方法转发调用的开销，虽然这样的性能影响是微乎其微的。
   *
   * // 除了上述的动态调用方式，还可以静态调用。
   * $collection = UserModel::select();
   *
   * // 如果是在模型方法中链式调用有以下方式
   * $this->query->select();
   * $this->query()->select();
   * self::select();
   * ```
   *
   * @return Query 模型查询构造器实例
   */
  public function query(): Query
  {
    return $this->query;
  }

  public function __get(string $name): mixed
  {
    return $this->__properties($name);
  }

  /**
   * 获取模型中的属性值，属性不存在时返回 null
   *
   * @param string $key 属性名
   * @return mixed 属性值，不存在时返回 null
   */
  public function __properties(string $key): mixed
  {
    if (property_exists($this, $key)) {
      return $this->{$key};
    } else {
      return null;
    }
  }

  public function __isset(string $name): bool
  {
    return property_exists($this, $name);
  }

  /**
   * 将实例方法调用转发到 Query 实例
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed Query 方法的返回值
   */
  public function __call(string $name, array $arguments)
  {
    return call_user_func_array([$this->query, $name], $arguments);
  }

  /**
   * 定义一对一关联关系
   *
   * @param Model|string $relationModel 关联模型实例或类名
   * @param string|null $foreignKey 关联模型中的外键名，为空时自动推断为"当前表名_当前主键"
   * @param string|null $localKey 当前模型的主键名，为空时使用 $pk 属性值
   * @return RelationQuery 关联查询实例
   */
  protected function hasOne(
    Model|string $relationModel,
    string       $foreignKey = null,
    string       $localKey = null,
  ): RelationQuery
  {
    return $this->_relation($relationModel, $foreignKey, $localKey);
  }

  /**
   * 构建关联查询实例
   *
   * @param Model|string $relationModel 关联模型实例或类名
   * @param string|null $foreignKey 关联模型中的外键名
   * @param string|null $localKey 当前模型的主键名
   * @param bool $many 是否为一对多关联
   * @return RelationQuery 关联查询实例
   */
  private function _relation(
    Model|string $relationModel,
    string       $foreignKey = null,
    string       $localKey = null,
    bool         $many = false
  ): RelationQuery
  {
    if (empty($localKey)) $localKey = $this->pk;
    if (empty($foreignKey)) $foreignKey = $this->table . '_' . $localKey;
    if (is_string($relationModel)) $relationModel = new $relationModel;
    return new RelationQuery($relationModel, $foreignKey, $localKey, $many);
  }

  /**
   * 定义一对多关联关系
   *
   * @param Model|string $relationModel 关联模型实例或类名
   * @param string|null $foreignKey 关联模型中的外键名，为空时自动推断为"当前表名_当前主键"
   * @param string|null $localKey 当前模型的主键名，为空时使用 $pk 属性值
   * @return RelationQuery 关联查询实例
   */
  protected function hasMany(
    Model|string $relationModel,
    string       $foreignKey = null,
    string       $localKey = null,
  ): RelationQuery
  {
    return $this->_relation($relationModel, $foreignKey, $localKey, true);
  }
}
