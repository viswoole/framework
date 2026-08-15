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

namespace Viswoole\Database\Query;

use InvalidArgumentException;
use Viswoole\Database\Facade\Db;

/**
 * WHERE 条件 Trait
 *
 * 提供丰富的查询条件构建方法，支持 AND/OR 连接、IN/BETWEEN/EXISTS 等操作符，
 * 以及条件分组和原生SQL条件。
 */
trait Where
{
  /** @var array 支持的 SQL 比较运算符列表 */
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
   * 添加 OR 连接的查询条件
   *
   * @param string $column 列名
   * @param string|int|float|array $operator 运算符；当 $value 为 null 时作为比较值
   * @param string|int|float|array|null $value 比较值，为 null 时 $operator 作为值
   * @return static 支持链式调用
   */
  public function orWhere(
    string                 $column,
    string|int|float|array $operator,
    string|int|float|array|null $value = null,
  ): static {
    return $this->where($column, $operator, $value, 'OR');
  }

  /**
   * 添加查询条件，支持简化写法（省略运算符时默认为 = 或 IN）
   *
   * @param string $column 列名
   * @param string|int|float|array $operator 运算符；当 $value 为 null 时作为比较值，数组自动转为 IN
   * @param string|int|float|array|null $value 比较值，为 null 时 $operator 作为值
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 运算符或连接符无效时抛出
   */
  public function where(
    string                 $column,
    string|int|float|array $operator,
    string|int|float|array|null $value = null,
    string                 $connector = 'AND'
  ): static {
    // IS NULL / IS NOT NULL 是 whereNull()/whereNotNull() 传入的合法操作符，
    // 不能进入下方"两参调用"兼容分支——否则 operator 被挪给 value，
    // 存为 {operator:'=', value:'IS NULL'}，SqlBuilder 的 null 分支永不触发，
    // 生成 "col = 'IS NULL'" 坏 SQL（MySQL 报 Incorrect DATETIME value）
    if (
      $value === null
      && !in_array($operator, ['IS NULL', 'IS NOT NULL'], true)
    ) {
      $value = $operator;
      $operator = is_array($operator) ? 'IN' : '=';
    }
    // 显式三参调用时 operator 由调用方提供，必须校验白名单，
    // 防止非法运算符被 SqlBuilder 直接内插进 SQL 造成注入
    if (!in_array($operator, self::OPERATORS, true)) {
      throw new InvalidArgumentException("无效的查询条件运算符：$operator");
    }
    if (is_array($value)) {
      if (empty($value)) throw new InvalidArgumentException("$operator 条件值不能是空数组");
    }
    $connector = strtoupper($connector);
    if (!in_array($connector, ['AND', 'OR'])) {
      throw new InvalidArgumentException('无效的条件连接符，仅只支持AND和OR');
    }
    $this->options->where[] = compact(
      'column',
      'operator',
      'value',
      'connector'
    );
    return $this;
  }

  /**
   * 批量设置查询条件，支持键值对和数组两种格式
   *
   * @param array $wheres 条件数组
   * @return static 支持链式调用
   */
  public function wheres(array $wheres): static
  {
    $wheres = WhereGroup::parsing($wheres);
    $this->options->where = array_merge($this->options->where, $wheres);
    return $this;
  }

  /**
   * 添加 AND 连接的查询条件（where 的显式写法）
   *
   * @param string $column 列名
   * @param string|int|float|array $operator 运算符
   * @param string|int|float|array|null $value 比较值
   * @return static 支持链式调用
   */
  public function andWhere(
    string                 $column,
    string|int|float|array $operator,
    string|int|float|array|null $value = null,
  ): static {
    return $this->where($column, $operator, $value);
  }

  /**
   * 添加 IN 条件
   *
   * @param string $column 列名
   * @param array $value 值列表
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 值为空数组时抛出
   */
  public function whereIn(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static {
    if (empty($value)) throw new InvalidArgumentException('IN条件值不能是空数组');
    return $this->where($column, 'IN', $value, $connector);
  }

  /**
   * 添加 NOT IN 条件
   *
   * @param string $column 列名
   * @param array $value 值列表
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 值为空数组时抛出
   */
  public function whereNotIn(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static {
    if (empty($value)) throw new InvalidArgumentException('NOT IN条件值不能是空数组');
    return $this->where($column, 'NOT IN', $value, $connector);
  }

  /**
   * 添加 IS NULL 条件
   *
   * @param string $column 列名
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   */
  public function whereNull(
    string $column,
    string $connector = 'AND'
  ): static {
    return $this->where($column, 'IS NULL', null, $connector);
  }

  /**
   * 添加 IS NOT NULL 条件
   *
   * @param string $column 列名
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   */
  public function whereNotNull(
    string $column,
    string $connector = 'AND'
  ): static {
    return $this->where($column, 'IS NOT NULL', null, $connector);
  }

  /**
   * 添加 NOT BETWEEN 条件
   *
   * @param string $column 列名
   * @param array $value 包含两个元素的区间数组
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 值为空数组时抛出
   */
  public function whereNotBetween(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static {
    if (empty($value)) throw new InvalidArgumentException('NOT BETWEEN条件值不能是空数组');
    return $this->where($column, 'NOT BETWEEN', $value, $connector);
  }

  /**
   * 添加 BETWEEN 条件
   *
   * @param string $column 列名
   * @param array $value 包含两个元素的区间数组
   * @param string $connector 条件连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   * @throws InvalidArgumentException 值为空数组时抛出
   */
  public function whereBetween(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static {
    if (empty($value)) throw new InvalidArgumentException('BETWEEN条件值不能是空数组');
    return $this->where($column, 'BETWEEN', $value, $connector);
  }

  /**
   * 添加条件分组，支持嵌套
   *
   * @param array $wheres 分组内的条件数组
   * @param string $connector 分组连接符 AND|OR，默认 AND
   * @return static 支持链式调用
   */
  public function whereGroup(array $wheres, string $connector = 'AND'): static
  {
    $this->options->where[] = new WhereGroup($wheres, $connector);
    return $this;
  }

  /**
   * 添加 EXISTS 条件
   *
   * @param string $sql 子查询SQL语句
   * @param array $bindings 子查询绑定参数
   * @return static 支持链式调用
   */
  public function whereExists(string $sql, array $bindings = []): static
  {
    return $this->whereRaw("EXISTS ($sql)", $bindings);
  }

  /**
   * 添加原生 WHERE 条件，直接嵌入SQL片段
   *
   * @param string $sql 原生SQL条件语句
   * @param array $bindings 绑定参数
   * @return static 支持链式调用
   */
  public function whereRaw(
    string $sql,
    array  $bindings = []
  ): static {
    $this->options->where[] = Db::raw($sql, $bindings);
    return $this;
  }

  /**
   * 添加 NOT EXISTS 条件
   *
   * @param string $sql 子查询SQL语句
   * @param array $bindings 子查询绑定参数
   * @return static 支持链式调用
   */
  public function whereNotExists(string $sql, array $bindings = []): static
  {
    return $this->whereRaw("NOT EXISTS ($sql)", $bindings);
  }
}
