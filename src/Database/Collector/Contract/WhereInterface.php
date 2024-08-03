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

namespace Viswoole\Database\Collector\Contract;

interface WhereInterface
{
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
  ): static;

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
  ): static;

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
  ): static;

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
  ): static;

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
  ): static;

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
  ): static;

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
  ): static;

  /**
   * 原生where语句
   *
   * @param string $sql where查询语句，不需要添加where!例如：`age > 10 AND name = '小明'`
   * @param array $params 参数
   * @return $this
   */
  public function whereRaw(string $sql, array $params = []): static;

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
   * @return $this
   */
  public function whereEq(
    string|array $column,
    mixed        $value,
    string       $connector = 'AND'
  ): static;

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
  ): static;

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
  public function whereGroup(array $wheres, string $connector = 'AND'): static;

  /**
   * 时间查询
   *
   *  Example:
   *  ```
   *   // 查询创建时间大于2024-01-01 12:24:50 数据
   *   $query->whereDate('create_time', '>', '2024-01-01 12:24:50');
   *   // 查询创建时间于'2024-01-01 12:24:50'-'2024-01-01 15:24:50'的数据
   *   $query->whereDate('create_time', 'BETWEEN', ['2024-01-01 12:24:50', '2024-01-01 15:24:50']);
   *   // ... 还支持其他比较运算符，LIKE 除外
   *  ```
   *
   * @param string $column 列
   * @param string $operator 比较运算符
   * @param string|int|array $time 日期时间，支持UNIX时间戳
   * @param string $connector
   * @return static
   */
  public function whereTime(
    string           $column,
    string           $operator,
    string|int|array $time,
    string           $connector = 'AND'
  ): static;

  /**
   * 日期查询，只比较日期，忽略时间部分，如果需要精确到时间请使用`whereTime()`。
   *
   * Example:
   * ```
   *  // 查询创建时间大于2024-01-01的数据
   *  $query->whereDate('create_time', '>', '2024-01-01');
   *  // 查询创建时间于2024-01月的数据
   *  $query->whereDate('create_time', 'BETWEEN', ['2024-01-01', '2024-01-31']);
   *  // ... 还支持其他比较运算符，LIKE 除外
   * ```
   *
   * @param string $column 时间字段
   * @param string $operator 运算符或日期
   * @param string|int|array $date 日期，支持UNIX时间戳
   * @param string $connector 条件连接符默认AND
   * @return static
   */
  public function whereDate(
    string           $column,
    string           $operator,
    string|int|array $date,
    string           $connector = 'AND'
  ): static;

  /**
   * 日期区间查询（精确到时间）
   *
   * Example:
   * ```
   *   // 查询 create_time 在 2024-01-01 12:01:00 到 2024-01-30 23:59:59 之间的数据
   *   $query->whereBetweenTime('create_time', '2024-01-01 12:01:00', '2024-01-30 23:59:59');
   * ```
   *
   * @param string $column 存储日期时间字段
   * @param string|int $startTime 开始日期时间
   * @param string|int $endTime 结束日期时间
   * @param string $connector 条件连接符默认AND
   * @return static
   */
  public function whereBetweenTime(
    string     $column,
    string|int $startTime,
    string|int $endTime,
    string     $connector = 'AND'
  ): static;

  /**
   * 查询某一年的数据
   *
   * @param string $column 日期字段
   * @param int|string $year 年份
   * @param string $connector 条件连接符默认AND
   * @return static
   */
  public function whereYear(
    string     $column,
    int|string $year,
    string     $connector = 'AND'
  ): static;

  /**
   * 日期区间查询
   *
   * @param string $column 日期字段
   * @param string|int $startDate 开始日期
   * @param string|int $endDate 结束日期
   * @param string $connector 条件连接符默认AND
   * @return static
   */
  public function whereBetweenDate(
    string     $column,
    string|int $startDate,
    string|int $endDate,
    string     $connector = 'AND'
  ): static;

  /**
   * 日期区间查询（不在日期区间）
   *
   * @param string $column 日期字段
   * @param string|int $startDate 开始日期
   * @param string|int $endDate 结束日期
   * @param string $connector 条件连接符默认AND
   * @return static
   */
  public function whereNotBetweenDate(
    string     $column,
    string|int $startDate,
    string|int $endDate,
    string     $connector = 'AND'
  ): static;

  /**
   * 时间区间查询（不在时间区间）
   *
   * @param string $column 日期字段
   * @param string|int $startDate 开始日期
   * @param string|int $endDate 结束日期
   * @param string $connector 条件连接符默认AND
   * @return static
   */
  public function whereNotBetweenTime(
    string     $column,
    string|int $startDate,
    string|int $endDate,
    string     $connector = 'AND'
  ): static;
}
