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

namespace Viswoole\Database\Query;

use InvalidArgumentException;
use Viswoole\Database\Facade\Db;

/**
 * 查询条件
 */
trait Where
{
  /**
   * 运算符
   */
  public const array OPERATORS = [
    '=',
    '!=',
    '<>',
    '>',
    '>=',
    '<',
    '<=',
    'LIKE',
    'BETWEEN',
    'NOT BETWEEN',
    'IN',
    'NOT IN',
    'IS NULL',
    'IS NOT NULL',
    'EXISTS',
    'NOT EXISTS'
  ];

  /**
   * OR 查询条件
   *
   * @access public
   * @param string $column 列名称
   * @param string|int|float|array $operator 比较运算符，表达式
   * @param string|int|float|array|null $value 值
   * @return $this
   */
  public function orWhere(
    string                 $column,
    string|int|float|array $operator,
    string|int|float|array $value = null,
  ): static
  {
    return $this->where($column, $operator, $value, 'OR');
  }

  /**
   * 查询条件
   *
   * @access public
   * @param string $column 列名称
   * @param string|int|float|array $operator 比较运算符，表达式
   * @param string|int|float|array|null $value 值, 如果为null，则使用$operator作为值
   * @param string $connector 条件连接符，AND或OR
   * @return $this
   */
  public function where(
    string                 $column,
    string|int|float|array $operator,
    string|int|float|array $value = null,
    string                 $connector = 'AND'
  ): static
  {
    if ($value === null) {
      $value = $operator;
      $operator = is_array($operator) ? 'IN' : '=';
    }
    if (is_array($value)) {
      if (empty($value)) throw new InvalidArgumentException("$operator 条件值不能是空数组");
    }
    $connector = strtoupper($connector);
    if (!in_array($connector, ['AND', 'OR'])) {
      throw new InvalidArgumentException('无效的条件连接符，仅只支持AND和OR');
    }
    $this->options->where[] = compact(
      'column', 'operator', 'value', 'connector'
    );
    return $this;
  }

  /**
   * 用数组批量设置查询条件
   *
   * Example:
   * ```
   * // 关联键值对元素
   * $query->wheres(['username'=>'小明','status'=>1])->find();
   * // 数组元素
   * $query->wheres([['username','=','小明'],['username','=','小红','OR']])->find();
   * // 混合使用
   * $query->wheres(['username'=>'小明',['username','=','小红','OR']])->find();
   * ```
   *
   * @param array $wheres
   * @return static
   */
  public function wheres(array $wheres): static
  {
    $wheres = WhereGroup::parsing($wheres);
    $this->options->where = array_merge($this->options->where, $wheres);
    return $this;
  }

  /**
   * 查询条件（AND）
   *
   * @access public
   * @param string $column 列名称
   * @param string|int|float|array $operator 比较运算符，表达式
   * @param string|int|float|array|null $value 值
   * @return $this
   */
  public function andWhere(
    string                 $column,
    string|int|float|array $operator,
    string|int|float|array $value = null,
  ): static
  {
    return $this->where($column, $operator, $value);
  }

  /**
   * 查询条件（IN）
   *
   * @access public
   * @param string $column 列名称
   * @param mixed $value 值
   * @param string $connector 条件连接符，AND或OR
   * @return static
   */
  public function whereIn(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static
  {
    if (empty($value)) throw new InvalidArgumentException('IN条件值不能是空数组');
    return $this->where($column, 'IN', $value, $connector);
  }

  /**
   * 查询条件（NOT IN）
   *
   * @access public
   * @param string $column 列名称
   * @param mixed $value 值
   * @param string $connector 条件连接符，AND或OR
   * @return $this
   */
  public function whereNotIn(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static
  {
    if (empty($value)) throw new InvalidArgumentException('NOT IN条件值不能是空数组');
    return $this->where($column, 'NOT IN', $value, $connector);
  }

  /**
   * 查询条件（IS NULL）
   *
   * @access public
   * @param string $column 列名称
   * @param string $connector 条件连接符，AND或OR
   * @return $this
   */
  public function whereNull(
    string $column,
    string $connector = 'AND'
  ): static
  {
    return $this->where($column, 'IS NULL', null, $connector);
  }

  /**
   * 查询条件（IS NOT NULL）
   *
   * @access public
   * @param string $column 列名称
   * @param string $connector 条件连接符，AND或OR
   * @return $this
   */
  public function whereNotNull(
    string $column,
    string $connector = 'AND'
  ): static
  {
    return $this->where($column, 'IS NOT NULL', null, $connector);
  }

  /**
   * 查询条件（NOT BETWEEN）
   *
   * @access public
   * @param string $column 列名称
   * @param mixed $value 值
   * @param string $connector 条件连接符，AND或OR
   * @return $this
   */
  public function whereNotBetween(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static
  {
    if (empty($value)) throw new InvalidArgumentException('NOT BETWEEN条件值不能是空数组');
    return $this->where($column, 'NOT BETWEEN', $value, $connector);
  }

  /**
   * 查询条件（BETWEEN）
   *
   * @access public
   * @param string $column 列名称
   * @param mixed $value 值
   * @param string $connector 条件连接符，AND或OR
   * @return $this
   */
  public function whereBetween(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static
  {
    if (empty($value)) throw new InvalidArgumentException('BETWEEN条件值不能是空数组');
    return $this->where($column, 'BETWEEN', $value, $connector);
  }

  /**
   * 查询条件组，支持嵌套
   *
   * @access public
   * @param array $wheres
   * @param string $connector
   * @return $this
   */
  public function whereGroup(array $wheres, string $connector = 'AND'): static
  {
    $this->options->where[] = new WhereGroup($wheres, $connector);
    return $this;
  }

  /**
   * 查询条件（EXISTS）
   *
   * @param string $sql sql语句
   * @param array $bindings 绑定的参数
   * @return $this
   */
  public function whereExists(string $sql, array $bindings = []): static
  {
    return $this->whereRaw("EXISTS ($sql)", $bindings);
  }

  /**
   * 原生 where 查询sql
   *
   * @access public
   * @param string $sql sql语句
   * @param array $bindings 绑定的参数
   * @return static
   */
  public function whereRaw(
    string $sql,
    array  $bindings = []
  ): static
  {
    $this->options->where[] = Db::raw($sql, $bindings);
    return $this;
  }

  /**
   * 查询条件（NOT EXISTS）
   *
   * @param string $sql sql语句
   * @param array $bindings 绑定的参数
   * @return static
   */
  public function whereNotExists(string $sql, array $bindings = []): static
  {
    return $this->whereRaw("NOT EXISTS ($sql)", $bindings);
  }
}
