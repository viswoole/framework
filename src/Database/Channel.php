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


use Viswoole\Database\Query\Options;

/**
 * 数据库通道
 */
abstract class Channel
{
  /**
   * 选择要查询的表
   *
   * @param string $table 表
   * @param string $pk 表主键名称
   * @return Query
   */
  public function table(string $table, string $pk = 'id'): Query
  {
    return new Query($this, $table, $pk);
  }

  /**
   * 查询
   *
   * @param string $sql SQL语句
   * @param array $bindings 参数
   * @return array
   */
  abstract public function query(
    string $sql,
    array  $bindings = []
  ): array;

  /**
   * 写入
   *
   * @param string $sql SQL语句
   * @param array $bindings 参数
   * @param bool $getId 是否获取写入数据的ID
   * @return int|string 返回自增主键或受影响的记录数
   */
  abstract public function execute(
    string $sql,
    array  $bindings = [],
    bool   $getId = false
  ): int|string;

  /**
   * 获取连接
   *
   * @param string $type 可选值`read`|`write`
   * @return mixed
   */
  abstract public function pop(string $type): mixed;

  /**
   * 归还连接
   *
   * @param mixed $connect
   * @return void
   */
  abstract public function put(mixed $connect): void;

  /**
   * 构建sql
   *
   * @param Options $options
   * @return Raw
   */
  abstract public function build(Options $options): Raw;
}
