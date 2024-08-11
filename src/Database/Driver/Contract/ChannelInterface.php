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

namespace Viswoole\Database\Driver\Contract;


use Viswoole\Database\Collector\CrudMethod;
use Viswoole\Database\Collector\QueryOptions;
use Viswoole\Database\Exception\DbException;

/**
 * 数据库驱动接口
 */
interface ChannelInterface
{
  /**
   * 原生查询
   *
   * @param string $sql
   * @param array $params
   * @return mixed 查询成功返回数据集
   * @throws DbException 查询失败抛出异常
   */
  public function query(string $sql, array $params = []): mixed;

  /**
   * 原生写入
   *
   * @param string $sql 要执行的查询语句
   * @param array $params 要绑定的参数
   * @param bool $getLastInsertId 是否返回最后插入的ID
   * @return int|string 查询成功返回受影响的行数
   * @throws DbException 查询失败抛出异常
   */
  public function execute(
    string $sql,
    array  $params = [],
    bool   $getLastInsertId = false
  ): int|string;

  /**
   * 原生执行sql
   *
   * @param string $sql
   * @param array $params
   * @return mixed 返回原生的执行结果，例如PDO驱动则返回PDOStatement对象
   */
  public function exec(string $sql, array $params = []): mixed;

  /**
   * 构建sql
   *
   * @param QueryOptions $options 查询选项
   * @param CrudMethod $crud crud方法
   * @param bool $merge 是否将参数和sql语句合并，不使用占位符
   * @return string|array{sql:string,params:array<string,mixed>}
   */
  public function build(
    QueryOptions $options,
    bool         $merge = true,
  ): string|array;
}
