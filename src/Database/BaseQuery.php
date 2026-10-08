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

namespace Viswoole\Database;

use InvalidArgumentException;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Query\Crud;
use Viswoole\Database\Query\Join;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Query\RunInfo;
use Viswoole\Database\Query\Where;

/**
 * 查询构造器
 *
 * 组合 Where、Join、Crud 三个 Trait，提供完整的查询构建能力。
 * 每个实例绑定一个 Channel 和一张表，通过 Options 管理查询状态。
 * 查询执行后自动 reset，可复用实例构建新查询。
 *
 * @see Options
 */
class BaseQuery
{
  use Where, Join, Crud;

  /**
   * @var Options 当前查询的配置选项
   */
  protected Options $options;
  /**
   * @var RunInfo 最近一次查询的运行信息
   */
  protected RunInfo $lastQuery;

  /**
   * 初始化查询构造器
   *
   * @param Channel $channel 数据库通道实例
   * @param string $table 表名
   * @param string $pk 主键字段名
   */
  public function __construct(
    protected Channel $channel,
    string            $table,
    string            $pk
  )
  {
    $this->options = new Options($table, $pk);
  }

  /**
   * 克隆时深拷贝 Options，避免多个实例共享同一 Options 对象
   */
  public function __clone(): void
  {
    $this->options = clone $this->options;
  }

  /**
   * 设置字段严格检测模式
   *
   * 开启时写入不存在的字段会抛出异常；关闭时自动忽略不存在的字段。
   *
   * @param bool $flag 是否启用严格模式，默认 true
   * @return static 支持链式调用
   */
  public function strict(bool $flag = true): static
  {
    $this->options->strict = $flag;
    return $this;
  }

  /**
   * 设置 GROUP BY 子句
   *
   * @param string|array $columns 分组依据的列名，多个列名用逗号隔开或使用数组
   * @return static 支持链式调用
   */
  public function groupBy(string|array $columns): static
  {
    if (is_string($columns)) {
      $columns = explode(',', $columns);
      array_walk($columns, function (&$value) {
        $value = trim($value);
      });
    }
    $this->options->groupBy = $columns;
    return $this;
  }

  /**
   * 获取最近一次查询的运行信息
   *
   * ⚠️ 仅实例方法语义：运行信息绑定在查询实例上。经 Model::__callStatic 静态
   * 调用（Model::getLastQuery()）时每次都会创建全新查询实例，永远返回 null——
   * 需要运行信息时须复用同一查询实例（如 $model->query->getLastQuery()）
   *
   * @return RunInfo|null 查询运行信息，未执行过查询时返回 null
   */
  public function getLastQuery(): ?RunInfo
  {
    if (!isset($this->lastQuery)) return null;
    return $this->lastQuery;
  }

  /**
   * 添加 HAVING 子句到分组查询
   *
   * @param string $column 列名
   * @param string $operator 比较运算符
   * @param mixed $value 比较值
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 运算符或连接符无效时抛出
   */
  public function having(
    string $column,
    string $operator,
    mixed  $value,
    string $connector = 'AND'
  ): static
  {
    // HAVING 子句由 SqlBuilder 原样内插 operator 与 connector，
    // 必须与 where() 同等做白名单校验，防止恶意片段注入 HAVING 子句
    $operator = strtoupper(trim($operator));
    // 仅放行标量比较运算符：IN/BETWEEN/IS NULL 等需独立生成逻辑，当前 HAVING 生成器不支持
    if (!in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE'], true)) {
      throw new InvalidArgumentException("无效的聚合条件运算符：$operator");
    }
    $connector = strtoupper($connector);
    if (!in_array($connector, ['AND', 'OR'], true)) {
      throw new InvalidArgumentException("无效的聚合条件连接符：{$connector}，仅支持 AND 和 OR");
    }
    $this->options->having[] = compact('column', 'operator', 'value', 'connector');
    return $this;
  }

  /**
   * 设置 ORDER BY 子句
   *
   * 支持多种调用方式：
   * ```
   * $query->orderBy('sort','desc');                          // 单列排序
   * $query->orderBy(['sort' => 'asc', 'age' => 'desc']);     // 多列指定方向
   * $query->orderBy(['sort','age'],'desc');                   // 多列统一方向
   * $query->orderBy(Db::raw('RAND()'));                       // 原生表达式排序
   * ```
   *
   * @param string|string[]|array<string,string>|Raw $column 排序依据的列名、列名数组或原生表达式
   * @param string $direction 排序方向 asc|desc，默认 asc
   * @return static 支持链式调用
   */
  public function orderBy(string|array|Raw $column, string $direction = 'asc'): static
  {
    $direction = strtoupper(trim($direction));
    $direction = in_array($direction, ['ASC', 'DESC']) ? $direction : 'ASC';
    if (is_array($column)) {
      foreach ($column as $key => $value) {
        if (is_int($key)) {
          $this->options->orderBy[] = [
            'column' => trim($value),
            'direction' => $direction
          ];
        } else {
          $value = strtoupper(trim($value));
          $this->options->orderBy[] = [
            'column' => $key,
            'direction' => in_array($value, ['ASC', 'DESC']) ? $value : 'ASC'
          ];
        }
      }
    } elseif ($column instanceof Raw) {
      $this->options->orderBy[] = $column;
    } else {
      $this->options->orderBy[] = [
        'column' => $column,
        'direction' => $direction
      ];
    }
    return $this;
  }

  /**
   * 设置分页查询
   *
   * 自动计算 offset 并设置 limit。
   *
   * @param int $page 页码，从 1 开始
   * @param int $pageSize 每页记录数
   * @return BaseQuery 支持链式调用
   */
  public function page(int $page, int $pageSize): BaseQuery
  {
    $page = max(1, $page);
    $pageSize = max(1, $pageSize);
    $offset = ($page - 1) * $pageSize;
    return $this->offset($offset)->limit($pageSize);
  }

  /**
   * 设置 LIMIT 子句
   *
   * @param int $limit 返回记录的最大数量
   * @return static 支持链式调用
   */
  public function limit(int $limit): static
  {
    $this->options->limit = $limit;
    return $this;
  }

  /**
   * 设置 OFFSET 子句
   *
   * @param int $offset 结果偏移量
   * @return static 支持链式调用
   */
  public function offset(int $offset): static
  {
    $this->options->offset = $offset;
    return $this;
  }

  /**
   * 添加 UNION 子句合并另一个查询结果
   *
   * @param string|Raw $query 要合并的 SQL 语句或 Raw 对象
   * @param string $type 合并类型 UNION|UNION ALL，默认 UNION
   * @return static 支持链式调用
   */
  public function union(string|Raw $query, string $type = 'UNION'): static
  {
    $type = strtoupper(trim($type)) === 'UNION ALL' ? 'UNION ALL' : 'UNION';
    $this->options->unions[] = compact('query', 'type');
    return $this;
  }

  /**
   * 设置是否返回唯一记录（DISTINCT）
   *
   * @param bool $flag 是否去重，默认 true
   * @return static 支持链式调用
   */
  public function distinct(bool $flag = true): static
  {
    $this->options->distinct = $flag;
    return $this;
  }

  /**
   * 设置强制索引（FORCE INDEX），仅 MySQL 和 SQLite 有效
   *
   * @param string $index 索引名称
   * @return static 支持链式调用
   */
  public function force(string $index): static
  {
    $this->options->force = $index;
    return $this;
  }

  /**
   * 设置表别名
   *
   * @param string $alias 别名
   * @return static 支持链式调用
   */
  public function alias(string $alias): static
  {
    $this->options->alias = $alias;
    return $this;
  }

  /**
   * 重置查询选项，保留表名和主键配置
   *
   * 为减少创建查询实例的开销，查询执行后会自动调用此方法重置条件。
   * 手动调用会丢失已构建的查询条件。
   *
   * @return static 支持链式调用
   */
  public function reset(): static
  {
    $this->options = new Options($this->options->table, $this->options->pk);
    return $this;
  }

  /**
   * 以原生 SQL 片段追加查询列（支持聚合/函数表达式等），等价于 columns(new Raw(...))
   *
   * ⚠️ $sql 直接参与 SQL 拼接（$bindings 走参数绑定），禁止将用户输入拼入片段
   *
   * @param string $sql 原生列表达式，可含 AS 别名
   * @param array $bindings 表达式内占位符的绑定参数
   * @return static 支持链式调用
   */
  public function selectRaw(string $sql, array $bindings = []): static
  {
    return $this->columns(new Raw($sql, $bindings));
  }

  /**
   * 设置要查询的列，支持别名（column AS alias）
   *
   * Raw 表达式原样追加（不解析不 quote，可携带自身绑定参数），
   * 用于函数表达式等原生片段，如 columns(new Raw('COUNT(*) AS cnt'))
   *
   * @param string|Raw ...$column 列名或原生表达式，不传则查询所有列
   * @return static 支持链式调用
   */
  public function columns(string|Raw ...$column): static
  {
    foreach ($column as $value) {
      if ($value instanceof Raw) {
        $this->options->columns[] = $value;
        continue;
      }
      if (str_contains($value, ' as ')) {
        $value = explode(' as ', $value);
        $this->options->columns[trim($value[0])] = trim($value[1]);
      } elseif (str_contains($value, ' AS ')) {
        $value = explode(' AS ', $value);
        $this->options->columns[trim($value[0])] = trim($value[1]);
      } else {
        $this->options->columns[$value] = null;
      }
    }
    return $this;
  }

  /**
   * 强制本次查询走主库（写连接），用于读写分离场景「刚写完立读」的一致性读取
   *
   * 写语句天然路由写库，本方法仅对 SELECT 有意义；不绕过显式配置的查询缓存
   * （->cache(...) 命中时仍返回缓存）。
   * 注意：SELECT ... FOR UPDATE / FOR SHARE 等锁定读已由框架自动路由写库，
   * 无需手动调用
   *
   * @param bool $force true=强制主库，false=恢复默认读写路由
   * @return static 支持链式调用
   */
  public function master(bool $force = true): static
  {
    $this->options->master = $force;
    return $this;
  }

  /**
   * 获取主键字段名
   *
   * @return string 主键字段名
   */
  public function getPrimaryKey(): string
  {
    return $this->options->pk;
  }

  /**
   * 获取当前查询的表名
   *
   * @return string 表名
   */
  public function getTableName(): string
  {
    return $this->options->table;
  }

  /**
   * 设置要排除的列
   *
   * @param string ...$column 要排除的列名
   * @return static 支持链式调用
   */
  public function withoutColumns(string ...$column): static
  {
    $this->options->withoutColumns = $column;
    return $this;
  }

  /**
   * 设置查询缓存，查询结果自动写入缓存，写入操作自动清除缓存
   *
   * @param string $key 缓存标识
   * @param int $expire 缓存有效期（秒），0 表示永不过期
   * @param string|null $tag 缓存标签，用于分组管理
   * @param string|null $store 缓存存储器名称，为 null 时使用默认存储器
   * @return static 支持链式调用
   */
  public function cache(
    string  $key,
    int     $expire = 0,
    ?string $tag = null,
    ?string $store = null
  ): static
  {
    $this->options->cache = compact('key', 'expire', 'tag', 'store');
    return $this;
  }

  /**
   * 添加排他锁（FOR UPDATE），其他事务不能读取或修改该记录
   *
   * @return static 支持链式调用
   */
  public function lockForUpdate(): static
  {
    $this->options->lockForUpdate = true;
    return $this;
  }

  /**
   * 添加共享锁（LOCK IN SHARE MODE），其他事务可读但不可修改
   *
   * @return static 支持链式调用
   */
  public function sharedLock(): static
  {
    $this->options->sharedLock = true;
    return $this;
  }

  /**
   * 标记当前查询仅返回 Raw 对象而不实际执行
   *
   * @return static 支持链式调用
   */
  public function toRaw(): static
  {
    $this->options->toRaw = true;
    return $this;
  }

  /**
   * 设置使用 REPLACE INTO 替代 INSERT INTO（仅 MySQL 有效）
   *
   * @param bool $flag 是否启用 REPLACE，默认 true
   * @return static 支持链式调用
   * @throws InvalidArgumentException 已设置 duplicate() 更新数据时抛出
   */
  public function replace(bool $flag = true): static
  {
    // 与 duplicate() 对称的入口守卫：已设置更新数据后启用 REPLACE 属误用，
    // 覆盖 duplicate() 在前、replace() 在后的调用顺序（构建期 parseDuplicate 亦兜底拦截）
    if ($flag && !empty($this->options->duplicate)) {
      throw new InvalidArgumentException('replace() 与 duplicate() 互斥，REPLACE INTO 无更新子句');
    }
    $this->options->replace = $flag;
    return $this;
  }

  /**
   * 设置 INSERT ... ON DUPLICATE KEY UPDATE 更新数据（仅 MySQL 有效）
   *
   * 配合 insert()/insertGetId() 使用，唯一键冲突时改为更新指定列：
   * ```
   * Db::table('user')->duplicate(['score' => 10])->insert(['name' => 'viswoole']);
   * // INSERT INTO `user` (`name`) VALUES (?) ON DUPLICATE KEY UPDATE `score` = ?
   * ```
   * 更新值支持原生表达式：duplicate(['num' => Db::raw('num + 1')])。
   * 传入 $rowAlias（MySQL 8.0.19+）生成 VALUES (...) AS alias 行别名，
   * 更新子句可以 alias.col 引用待插入值（替代 8.0.20 起废弃的 VALUES(col) 惯用法）：
   * ```
   * Db::table('config')->duplicate([
   *   'value' => Db::raw('new.value'),
   * ], rowAlias: 'new')->insert($data);
   * // INSERT INTO `config` (...) VALUES (...) AS new
   * //   ON DUPLICATE KEY UPDATE `value` = new.value
   * ```
   * 注意：与 replace() 互斥；批量写入时更新子句仅拼接一次，对所有冲突行生效；
   * 重复调用为整体覆盖（非追加合并）语义。
   * ⚠️ 仅对 insert()/insertGetId()/create() 生效：以 update()/delete() 收尾时
   * 已设置的更新数据会被静默忽略，请勿混用。
   * ⚠️ 返回值语义（MySQL ODKU 行为，勿当作普通 insert 解读）：
   * 1. insert() 返回的受影响行数为 0（冲突且值未变）/ 1（新插入）/ 2（冲突更新）；
   * 2. insertGetId()/create() 在命中冲突更新路径时，LAST_INSERT_ID 返回的是
   *    该连接上一次成功插入的自增值（连接池复用场景下常见过期/无关值），
   *    create() 会将其回填到数据集主键——需要可靠取回冲突行 ID 时，
   *    应在更新子句使用惯用法 duplicate(['id' => Db::raw('LAST_INSERT_ID(id)')])，
   *    生成 `id` = LAST_INSERT_ID(id)（Raw 仅为赋值右侧表达式，勿自带 "id =" 前缀，
   *    否则生成双重赋值布尔表达式导致主键被覆写）。
   *
   * @param array<string,mixed|Raw> $data 更新数据，键为列名、值为标量或 Raw 表达式
   * @param string $rowAlias VALUES 行别名（MySQL 8.0.19+），空串表示不使用
   * @return static 支持链式调用
   * @throws InvalidArgumentException data 为空、rowAlias 非法、或同时启用 replace 时抛出
   */
  public function duplicate(array $data, string $rowAlias = ''): static
  {
    if (empty($data)) throw new InvalidArgumentException(
      'ON DUPLICATE KEY UPDATE 更新数据不能为空'
    );
    if ($this->options->replace) {
      throw new InvalidArgumentException('duplicate() 与 replace() 互斥，REPLACE INTO 无更新子句');
    }
    // 行别名参与 SQL 拼接，入口做与 quote() 一致的标识符校验，防止片段注入
    if ($rowAlias !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $rowAlias)) {
      throw new InvalidArgumentException("无效的 VALUES 行别名：{$rowAlias}（仅允许字母、数字、下划线）");
    }
    $this->options->duplicate = $data;
    $this->options->duplicateRowAlias = $rowAlias;
    return $this;
  }

  /**
   * 行级水合出口：把单行数据包装为本查询的行数据载体
   *
   * 默认包装为 DataSet；模型查询（Model\Query）与实体查询（EntityQuery）
   * 均基于此出口承接行数据（关联合并、create、find 等路径），
   * 实体查询覆写为返回实体实例。public 供关联查询跨类调用。
   *
   * @param array<string,mixed> $row 行数据
   * @return DataSet|Entity 单行数据载体
   */
  public function newRowSet(array $row): DataSet|Entity
  {
    return new DataSet($this->newQuery(), $row);
  }

  /**
   * 创建一个全新的查询实例，共享当前通道和表配置
   *
   * @return static 新的查询实例
   */
  public function newQuery(): static
  {
    return new static($this->channel, $this->options->table, $this->options->pk);
  }

  /**
   * 集合级水合出口：把行数据列表包装为本查询的多行载体
   *
   * 默认包装为 Collection；实体查询（EntityQuery）覆写为返回 EntityCollection。
   * public 供关联查询（RelationQuery/BelongsToMany）跨类调用。
   *
   * @param array<int,mixed> $rows 行数据列表（实体路径也可传入已水合的实体）
   * @return BaseCollection 多行数据载体
   */
  public function newRowsCollection(array $rows = []): BaseCollection
  {
    return new Collection($this->newQuery(), $rows);
  }
}
