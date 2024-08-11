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

namespace Viswoole\Database\Collector\Trait;

use Viswoole\Database\Collector\Where\WhereGroup;
use Viswoole\Database\Collector\Where\WhereRaw;

/**
 * 查询条件
 */
trait WhereTrait
{
  use DateTrait;

  /**
   * 查询条件(AND连接符)
   *
   * @param string|array $column 要筛选的列(字段名称)
   * @param mixed $operator 比较运算符或需要比对的值
   * @param mixed $value 比较值
   * @return static
   */
  public function whereAnd(
    string|array $column,
    mixed        $operator,
    mixed        $value,
  ): static
  {
    return $this->where($column, $operator, $value);
  }

  /**
   * 查询条件
   *
   * @param string|array $column 要筛选的列(字段名称)
   * @param mixed $operator 比较运算符或需要比对的值
   * @param mixed $value 比较值
   * @param string $connector 连接符,用于连接上一个查询条件，开头第一个条件该参数无效
   * @return static
   */
  public function where(
    string|array $column,
    mixed        $operator,
    mixed        $value,
    string       $connector = 'AND'
  ): static
  {
    if (is_array($column)) {
      foreach ($column as $key => $item) {
        if (is_string($key)) {
          $this->where($key, '=', $item);
        } else {
          $this->where(...$item);
        }
      }
    } else {
      $this->options->addWhere($column, $operator, $value, $connector);
    }
    return $this;
  }

  /**
   * 查询条件(OR连接符)
   *
   * @param string|array $column 要筛选的列(字段名称)
   * @param mixed $operator 比较运算符或需要比对的值
   * @param mixed $value 比较值
   * @return static
   */
  public function whereOr(
    string|array $column,
    mixed        $operator,
    mixed        $value,
  ): static
  {
    return $this->where($column, $operator, $value, 'OR');
  }

  /**
   * 快捷查询IN 例如 查询列的值是否为1|2|3 value则传入[1,2,3]
   *
   * @param string $column 要筛选的列(字段名称)
   * @param array $value 比较值数组
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereIn(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static
  {
    return $this->where($column, 'IN', $value, $connector);
  }

  /**
   * 快捷查询NOT IN 例如 查询列的值不是1|2|3 value则传入[1,2,3]
   *
   * @param string $column 要筛选的列(字段名称)
   * @param array $value 比较值数组
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereNotIn(
    string $column,
    array  $value,
    string $connector = 'AND'
  ): static
  {
    return $this->where($column, 'NOT IN', $value, $connector);
  }

  /**
   * 快捷查询BETWEEN
   *
   * @param string $column 字段名称
   * @param int|string $start 开始值
   * @param int|string $end 结束值
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereBetween(
    string     $column,
    int|string $start,
    int|string $end,
    string     $connector = 'AND'
  ): static
  {
    return $this->where($column, 'BETWEEN', [$start, $end], $connector);
  }

  /**
   * 快捷查询NOT BETWEEN
   *
   * @param string $column 字段名称
   * @param int|string $start 开始值
   * @param int|string $end 结束值
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereNotBetween(
    string     $column,
    int|string $start,
    int|string $end,
    string     $connector = 'AND'
  ): static
  {
    return $this->where($column, 'NOT BETWEEN', [$start, $end], $connector);
  }

  /**
   * where组查询，用括号包裹条件
   *
   * Example:
   * ```
   * $query->table('users')->where('name','=','小明')->whereGroup([['age','=',10],['posts','=','HR','OR']])->find();
   * // 以上查询条件会生成的sql如下
   * SELECT * FROM `users` WHERE `name` = '小明' AND (`age` = 10 OR `posts` = 'HR')
   * ```
   *
   * @param array $wheres
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereGroup(array $wheres, string $connector = 'AND'): static
  {
    $this->options->where[] = new WhereGroup($wheres, $connector);
    return $this;
  }

  /**
   * 快捷查询Like
   *
   * @param string $column 字段名称
   * @param string $word 要匹配的字符串
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereLike(
    string $column,
    string $word,
    string $connector = 'AND'
  ): static
  {
    return $this->where($column, 'LIKE', $word, $connector);
  }

  /**
   * 快捷查询 equal
   *
   * Example:
   * ```
   * // 查询名字 等于 小明的记录
   * $query->table('users')->whereEq('name','小明')->find();
   * ```
   *
   * @param string|array $column 列
   * @param mixed $value 值
   * @param string $connector 连接符,用于连接上一个查询条件
   * @return static
   */
  public function whereEq(
    string|array $column,
    mixed        $value,
    string       $connector = 'AND'
  ): static
  {
    return $this->where($column, '=', $value, $connector);
  }

  /**
   * 原生where语句
   *
   * @param string $sql where查询语句，不需要添加where!例如：`age > 10 AND name = '小明'`
   * @param array $params 参数
   * @return $this
   */
  public function whereRaw(string $sql, array $params = []): static
  {
    $this->options->where[] = new WhereRaw($sql, $params);
    return $this;
  }
}
