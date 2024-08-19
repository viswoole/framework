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
   * @return Collector
   */
  public function table(string $table, string $pk = 'id'): Collector
  {
    return new Collector($this, $table, $pk);
  }

  /**
   * 查询
   *
   * @param string $sql SQL语句
   * @param array $params 参数
   * @return array 如果是聚合查询则返回int或string
   */
  abstract public function query(
    string $sql,
    array  $params = []
  ): array;

  /**
   * 写入
   *
   * @param string $sql SQL语句
   * @param array $params 参数
   * @param bool $getId 是否获取写入数据的ID
   * @return int|string 返回写入数据的ID或受影响的记录数
   */
  abstract public function execute(
    string $sql,
    array  $params = [],
    bool   $getId = false
  ): int|string;

  /**
   * 获取连接
   *
   * @param string $type
   * @return mixed
   */
  abstract public function pop(string $type = 'write'): mixed;

  /**
   * 归还连接
   *
   * @param mixed $connect
   * @return void
   */
  abstract public function put(mixed $connect): void;
}
