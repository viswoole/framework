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
use Viswoole\Database\Query\Where;

/**
 * 查询构造器
 */
class Query
{
  use Where, Join, Crud;

  /**
   * @var Options 查询配置选项
   */
  protected Options $options;

  /**
   * 表名称
   *
   * @param Channel $channel 数据库通道名称
   * @param string $table 表
   * @param string $pk
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
   * 对结果进行分组
   *
   * @access public
   * @param string|array $columns 分组依据的列名，多个列名用逗号隔开或使用数组
   * @return static
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
   * 添加 HAVING 子句到分组查询。
   *
   * @access public
   * @param string $column 列名。
   * @param string $operator 操作符。
   * @param mixed $value 值。
   * @param string $connector 连接符，可选，默认为 'AND'。
   * @return static
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
   * 对结果进行排序。
   *
   * @access public
   * @param string|string[]|array<string,string> $column 排序依据的列名
   * @param string $direction 排序方向，可选`asc`|`desc`,默认为`asc`。
   * @return static
   */
  public function orderBy(string|array $column, string $direction = 'asc'): static
  {
    $direction = strtoupper(trim($direction));
    $direction = in_array($direction, ['ASC', 'DESC']) ? $direction : 'ASC';
    if (is_array($column)) {
      foreach ($column as $key => $value) {
        if (is_int($key)) {
          $this->options->orderBy[trim($value)] = $direction;
        } else {
          $value = strtoupper(trim($value));
          $this->options->orderBy[$key] = in_array($value, ['ASC', 'DESC']) ? $value : 'ASC';
        }
      }
    } else {
      $this->options->orderBy[$column] = $direction;
    }
    return $this;
  }

  /**
   * 分页查询
   *
   * @access public
   * @param int $page 页码
   * @param int $pageSize 每页数量
   * @return Query
   */
  public function page(int $page, int $pageSize): Query
  {
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $pageSize;
    return $this->offset($offset)->limit($pageSize);
  }

  /**
   * 限制返回的结果数量。
   *
   * @param int $limit 结果数量。
   * @return static
   */
  public function limit(int $limit): static
  {
    $this->options->limit = $limit;
    return $this;
  }

  /**
   * 设置结果的偏移量。
   *
   * @param int $offset 偏移量。
   * @return static
   */
  public function offset(int $offset): static
  {
    $this->options->offset = $offset;
    return $this;
  }

  /**
   * 合并另一个查询结果。
   *
   * @access public
   * @param string|Raw $query 另一个查询的 SQL 语句。
   * @param string $type 合并类型，默认为 'UNION'，可选 'UNION'|'UNION ALL'。
   * @return static
   */
  public function union(string|Raw $query, string $type = 'UNION'): static
  {
    $type = strtoupper(trim($type)) === 'UNION ALL' ? 'UNION ALL' : 'UNION';
    $this->options->unions[] = compact('query', 'type');
    return $this;
  }

  /**
   * 设置查询结果是否返回唯一记录。
   *
   * @access public
   * @param bool $flag 是否返回唯一记录，默认为 false。
   * @return static
   */
  public function distinct(bool $flag = true): static
  {
    $this->options->distinct = $flag;
    return $this;
  }

  /**
   * 强制索引
   *
   * @access public
   * @param string $index
   * @return static
   */
  public function force(string $index): static
  {
    $this->options->force = $index;
    return $this;
  }

  /**
   * 表别名
   *
   * @param string $alias
   * @return $this
   */
  public function alias(string $alias): static
  {
    $this->options->alias = $alias;
    return $this;
  }

  /**
   * 选择要查询的列。
   *
   * @access public
   * @param string ...$column 要查询的列名,不传则默认查询所有列。
   * @return static
   */
  public function columns(string...$column): static
  {
    $columns = [];
    if (!empty($column)) {
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
    }
    $this->options->columns = array_merge($this->options->columns, $columns);
    return $this;
  }

  /**
   * 排除字段
   *
   * @access public
   * @param string ...$column
   * @return static
   */
  public function withoutColumns(string ...$column): static
  {
    $this->options->withoutColumns = is_string($column) ? explode(',', $column) : $column;
    return $this;
  }

  /**
   * 锁定记录以进行更新。
   *
   * 其他事务不能读取或修改该记录
   *
   * @access public
   * @return $this
   */
  public function lockForUpdate(): static
  {
    $this->options->lockForUpdate = true;
    return $this;
  }

  /**
   * 共享锁定记录。
   *
   * 其他事务可以读取该记录，但无法修改
   *
   * @access public
   * @return $this
   */
  public function sharedLock(): static
  {
    $this->options->sharedLock = true;
    return $this;
  }

  /**
   * 获取sql语句，不执行任何查询
   *
   *
   * @access public
   * @return $this
   */
  public function getSql(): static
  {
    $this->options->getSql = true;
    return $this;
  }

  /**
   * 强制写入
   *
   * @param bool $flag
   * @return $this
   */
  public function replace(bool $flag = true): static
  {
    $this->options->replace = $flag;
    return $this;
  }
}
