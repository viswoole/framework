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

namespace Viswoole\Database;

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
   */
  public function having(
    string $column,
    string $operator,
    mixed  $value,
    string $connector = 'AND'
  ): static
  {
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
   * 设置要查询的列，支持别名（column AS alias）
   *
   * @param string ...$column 列名，不传则查询所有列
   * @return static 支持链式调用
   */
  public function columns(string...$column): static
  {
    if (!empty($column)) {
      $columns = [];
      foreach ($column as $value) {
        if (str_contains($value, ' as ')) {
          $value = explode(' as ', $value);
          $columns[trim($value[0])] = trim($value[1]);
        } elseif (str_contains($value, ' AS ')) {
          $value = explode(' AS ', $value);
          $columns[trim($value[0])] = trim($value[1]);
        } else {
          $columns[$value] = null;
        }
      }
      $this->options->columns = array_merge($this->options->columns, $columns);
    }
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
    string $key,
    int    $expire = 0,
    string $tag = null,
    string $store = null
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
   */
  public function replace(bool $flag = true): static
  {
    $this->options->replace = $flag;
    return $this;
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
}
