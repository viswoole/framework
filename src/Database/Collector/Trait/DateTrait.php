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

/**
 * 日期快捷查询
 */
trait DateTrait
{
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
  ): static
  {
    return $this->whereDate($column, 'BETWEEN', [$startDate, $endDate], $connector);
  }

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
  ): static
  {
    if (is_int($date)) {
      $date = date('Y-m-d', $date);
    } elseif (is_array($date)) {
      foreach ($date as &$t) if (is_int($t)) $t = date('Y-m-d', $t);
    }
    $operator = $operator . ' DATE';
    $this->where($column, $operator, $date, $connector);
    return $this;
  }

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
  ): static
  {
    return $this->whereDate($column, 'NOT BETWEEN', [$startDate, $endDate], $connector);
  }

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
  ): static
  {
    return $this->whereTime($column, 'NOT BETWEEN', [$startDate, $endDate], $connector);
  }

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
  ): static
  {
    if (is_int($time)) {
      $time = date('Y-m-d H:i:s', $time);
    } elseif (is_array($time)) {
      foreach ($time as &$t) if (is_int($t)) $t = date('Y-m-d H:i:s', $t);
    }
    $operator = $operator . ' TIME';
    $this->where($column, $operator, $time, $connector);
    return $this;
  }

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
  ): static
  {
    $start = $year . '-01-01 00:00:00';
    $end = $year . '-12-31 23:59:59';
    return $this->whereBetweenTime($column, $start, $end, $connector);
  }

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
  ): static
  {
    return $this->whereTime($column, 'BETWEEN', [$startTime, $endTime], $connector);
  }
}
