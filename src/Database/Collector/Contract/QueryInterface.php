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

/**
 * 链式查询条件收集器
 */
interface QueryInterface
{
  /**
   * 要查询的字段
   *
   * @access public
   * @param string|array $fields 要查询的字段
   * @return static
   */
  public function field(string|array $fields = '*'): static;

  /**
   * 要排除的字段
   *
   * @access public
   * @param string|array $fields 要排除的字段
   * @return static
   */
  public function withoutField(string|array $fields): static;

  /**
   * 设置别名
   *
   * @access public
   * @param string $alias
   * @return static
   */
  public function alias(string $alias): static;

  /**
   * 分页查询
   *
   * @access public
   * @param int $page 查询第几页
   * @param int $limit 查询多少条数据
   * @return static
   */
  public function page(int $page = 1, int $limit = 10): static;

  /**
   * 排序
   *
   * @access public
   * @param string $column
   * @param string $direction
   * @return static
   */
  public function order(string $column, string $direction = 'ASC'): static;

  /**
   * 分组
   *
   * @access public
   * @param string|array $columns
   * @return static
   */
  public function group(string|array $columns): static;

  /**
   * 用于配合group方法完成从分组的结果中筛选
   *
   * @access public
   * @param string $condition
   * @return static
   */
  public function having(string $condition): static;


  /**
   * 筛选不重复的值
   *
   * @access public
   * @param bool $distinct
   * @return static
   */
  public function distinct(bool $distinct = true): static;

  /**
   * 构建sql
   *
   * @param string $crud crud方法，可选值：['INSERT', 'DELETE', 'UPDATE', 'SELECT', 'FIND']
   * @param bool $merge 是否将参数和sql语句合并，不使用占位符
   * @return string|array{sql:string,params:array<string,mixed>}
   */
  public function build(string $crud = 'SELECT', bool $merge = false): string|array;

  /**
   * 设置写入/更新数据
   *
   * @param array $data 要更新/写入的数据
   * @param bool $merge 是否合并已有数据
   * @return static
   */
  public function data(array $data, bool $merge = false): static;

  /**
   * 强制索引
   *
   * @access public
   * @param string $index
   * @return static
   */
  public function force(string $index): static;

  /**
   * 添加注释
   *
   * @access public
   * @param string $comment
   * @return static
   */
  public function comment(string $comment): static;

  /**
   * 分区查询（仅mysql可用）
   * 如果你想查看表的分区信息，可以使用 SHOW TABLE STATUS 命令 ：
   * SHOW TABLE STATUS LIKE 'table_name'
   *
   * @access public
   * @param string|array $partition
   * @return static
   */
  public function partition(string|array $partition): static;

  /**
   * 查询数据为空时是否抛出错误
   *
   * @access public
   * @param bool $allow 设置为false则未查询到数据时会抛出异常
   * @return static
   */
  public function allowEmpty(bool $allow = false): static;


  /**
   * 设置replace写入(仅mysql可用)
   * 如果数据存在则删除数据再写入 等同于强制写入
   *
   * @return static
   */
  public function replace(): static;

  /**
   * 限制查询数量
   *
   * @access public
   * @param int $limit 要查询的数量
   * @return static
   */
  public function limit(int $limit): static;

  /**
   * 偏移量
   *
   * @param int $offset 偏移量
   * @return static
   */
  public function offset(int $offset): static;

  /**
   * 自动写入缓存
   *
   * @param string|bool $key 缓存标识
   * @param int $expiry 缓存有效时间 默认0永久
   * @param string $tag 缓存标签 默认db_cache
   * @return static
   */
  public function cache(
    string|bool $key = true,
    int         $expiry = 0,
    string      $tag = 'db_cache'
  ): static;

  /**
   * 合并多个语句
   *
   * @access public
   * @param string|array $sql
   * @return static
   */
  public function union(string|array $sql): static;

  /**
   * 合并多个语句
   *
   * @access public
   * @param string|array $sql
   * @return static
   */
  public function unionAll(string|array $sql): static;


  /**
   * 获取主键
   *
   * @access public
   * @return string
   */
  public function getPrimaryKey(): string;
}
