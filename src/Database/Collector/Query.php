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

namespace Viswoole\Database\Collector;

use Override;
use Viswoole\Database\Collector\Contract\CrudInterface;
use Viswoole\Database\Collector\Contract\JoinInterface;
use Viswoole\Database\Collector\Contract\QueryInterface;
use Viswoole\Database\Collector\Contract\WhereInterface;
use Viswoole\Database\Driver\Contract\DriverInterface;

class Query implements WhereInterface, JoinInterface, CrudInterface, QueryInterface
{
  use WhereQuery;
  use JoinQuery;
  use CrudQuery;

  public const array CRUD = ['INSERT', 'DELETE', 'UPDATE', 'SELECT', 'FIND'];
  /**
   * @var QueryOptions 当前查询参数
   */
  private QueryOptions $options;

  /**
   * 构建查询器
   *
   * @param DriverInterface $driver 数据库驱动
   * @param string $table 表名
   * @param string $pk 主键
   */
  public function __construct(
    protected DriverInterface $driver,
    protected string          $table,
    protected string          $pk
  )
  {
    $this->options = new QueryOptions($this->table, $this->driver->prefix(), $this->pk);
  }

  /**
   * 要查询的字段
   *
   * @access public
   * @param array|string|Raw $fields 要查询的字段
   * @return static
   */
  #[Override] public function field(array|string|Raw $fields = '*'): static
  {
    if ($fields !== '*') $fields = $this->stringFieldToArray($fields);
    $this->options->field = $fields;
    return $this;
  }

  /**
   * 把选择的字段转换为数组
   *
   * @param array|string $fields
   * @return array|string
   */
  protected function stringFieldToArray(array|string $fields): string|array
  {
    $parsedFields = [];
    if (is_string($fields)) {
      $fields = explode(',', trim($fields));
      foreach ($fields as $item) {
        $fieldParts = explode(' as ', trim($item));
        if (count($fieldParts) === 2) $alias = trim($fieldParts[1]);
        $parsedFields[trim($fieldParts[0])] = $alias ?? null;
      }
    } else {
      foreach ($fields as $key => $value) {
        if (is_int($key)) {
          $parsedFields[$value] = null;
        } else {
          $parsedFields[$key] = $value;
        }
      }
    }
    return $parsedFields;
  }

  /**
   * 要排除的字段
   *
   * @access public
   * @param string|string[] $fields 要排除的字段
   * @return static
   */
  #[Override] public function withoutField(array|string $fields): static
  {
    $this->options->withoutField = is_string($fields) ? [$fields] : $fields;
    return $this;
  }

  /**
   * 设置别名
   *
   * @access public
   * @param string $alias
   * @return static
   */
  #[Override] public function alias(string $alias): static
  {
    $this->options->alias = $alias;
    return $this;
  }

  /**
   * 分页查询
   *
   * @access public
   * @param int $page 查询第几页
   * @param int $limit 查询多少条数据
   * @return static
   */
  #[Override] public function page(int $page = 1, int $limit = 10): static
  {
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;
    $this->options->offset = $offset;
    $this->options->limit = $limit;
    return $this;
  }

  /**
   * 排序
   *
   * @access public
   * @param string|array $column
   * @param string $direction
   * @return static
   */
  #[Override] public function order(string|array $column, string $direction = 'ASC'): static
  {
    $direction = strtoupper($direction);
    $direction = $direction === 'ASC' ? 'ASC' : 'DESC';
    if (is_string($column)) $column = [$column];
    $this->options->order = compact('column', 'direction');
    return $this;
  }

  /**
   * 分组
   *
   * @access public
   * @param string|array $columns
   * @return static
   */
  #[Override] public function group(array|string $columns): static
  {
    if (is_string($columns)) $columns = explode(',', $columns);
    $this->options->group = $columns;
    return $this;
  }

  /**
   * 用于配合group方法完成从分组的结果中筛选
   *
   * @access public
   * @param string $condition
   * @return static
   */
  #[Override] public function having(string $condition): static
  {
    $this->options->having = $condition;
    return $this;
  }

  /**
   * 筛选不重复的值
   *
   * @access public
   * @param bool $distinct
   * @return static
   */
  #[Override] public function distinct(bool $distinct = true): static
  {
    $this->options->distinct = $distinct;
    return $this;
  }

  /**
   * 强制索引
   *
   * @access public
   * @param string $index
   * @return static
   */
  #[Override] public function force(string $index): static
  {
    $this->options->force = $index;
    return $this;
  }

  /**
   * 添加注释
   *
   * @access public
   * @param string $comment
   * @return static
   */
  #[Override] public function comment(string $comment): static
  {
    $this->options->comment = $comment;
    return $this;
  }

  /**
   * 分区查询（仅mysql可用）
   * 如果你想查看表的分区信息，可以使用 SHOW TABLE STATUS 命令 ：
   * SHOW TABLE STATUS LIKE 'table_name'
   *
   * @access public
   * @param string|array $partition
   * @return static
   */
  #[Override] public function partition(array|string $partition): static
  {
    if (is_string($partition)) $partition = [$partition];
    $this->options->partition = $partition;
    return $this;
  }

  /**
   * 查询数据为空时是否抛出错误
   *
   * @access public
   * @param bool $allow 设置为false则未查询到数据时会抛出异常
   * @return static
   */
  #[Override] public function allowEmpty(bool $allow = false): static
  {
    $this->options->allowEmpty = $allow;
    return $this;
  }

  /**
   * 设置replace写入(仅mysql可用)
   * 如果数据存在则删除数据再写入 等同于强制写入
   *
   * @return static
   */
  #[Override] public function replace(): static
  {
    $this->options->replace = true;
    return $this;
  }

  /**
   * 限制查询数量
   *
   * @access public
   * @param int $limit 要查询的数量
   * @return static
   */
  #[Override] public function limit(int $limit): static
  {
    $this->options->limit = $limit;
    return $this;
  }

  /**
   * 偏移量
   *
   * @param int $offset 偏移量
   * @return static
   */
  #[Override] public function offset(int $offset): static
  {
    $this->options->offset = $offset;
    return $this;
  }

  /**
   * 自动写入缓存
   *
   * @param string|bool $key 缓存标识
   * @param int $expiry 缓存有效时间 默认0永久
   * @param string|null $tag 缓存标签
   * @return static
   */
  #[Override] public function cache(
    bool|string $key = true,
    int         $expiry = 0,
    ?string     $tag = null
  ): static
  {
    $this->options->cache = $key;
    $this->options->cache_expiry = $expiry;
    $this->options->cache_tag = $tag;
    return $this;
  }

  /**
   * 合并多个语句
   *
   * @access public
   * @param string|array $sql
   * @return static
   */
  #[Override] public function union(array|string $sql): static
  {
    if (is_string($sql)) $sql = [$sql];
    $this->options->union = $sql;
    return $this;
  }

  /**
   * 合并多个语句
   *
   * @access public
   * @param string|array $sql
   * @return static
   */
  #[Override] public function unionAll(array|string $sql): static
  {
    if (is_string($sql)) $sql = [$sql];
    $this->options->unionAll = $sql;
    return $this;
  }

  /**
   * 获取主键字段
   *
   * @access public
   * @return string
   */
  #[Override] public function getPrimaryKey(): string
  {
    return $this->pk;
  }

  /**
   * 自减
   *
   * @param string|Raw $column
   * @param float|int $step
   * @return static
   */
  public function dec(
    string|Raw $column,
    float|int  $step = 1,
  ): static
  {
    $this->options->autoDec[] = ['column' => $column, 'step' => $step];
    return $this;
  }

  /**
   * 自增
   *
   * @param string|Raw $column
   * @param float|int $step
   * @return static
   */
  public function inc(
    string|Raw $column,
    float|int  $step = 1,
  ): static
  {
    $this->options->autoInc[] = ['column' => $column, 'step' => $step];
    return $this;
  }

  /**
   * 构建sql
   *
   * @param string $crud crud方法，可选值：['INSERT', 'DELETE', 'UPDATE', 'SELECT', 'FIND']
   * @param bool $merge 是否将参数和sql语句合并，不使用占位符
   * @return string|array{sql:string,params:array<string,mixed>}
   */
  #[Override] public function build(string $crud = 'SELECT', bool $merge = true): string|array
  {
    $crud = strtoupper($crud);
    if (!in_array($crud, self::CRUD)) $crud = 'SELECT';
    $this->options->queryType = $crud;
    $this->options->getSql = $merge ? 2 : 1;
    $currentOptions = clone $this->options;
    $this->options = new QueryOptions($this->table, $this->driver->prefix(), $this->pk);
    return $this->driver->builder($currentOptions);
  }

  /**
   * 设置写入/更新数据
   *
   * @param array $data 要更新/写入的数据
   * @param bool $merge 是否合并已有数据
   * @return static
   */
  #[Override] public function data(array $data, bool $merge = false): static
  {
    $this->options->data = $merge ? array_merge($this->options->data, $data) : $data;
    return $this;
  }
}
