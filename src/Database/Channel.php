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


use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Query\Options;

/**
 * 数据库通道抽象基类
 *
 * 定义数据库通道的标准接口：查询构建、SQL执行、连接获取与归还。
 * 具体实现（如 PDOChannel）负责连接池管理和驱动适配。
 *
 * @see \Viswoole\Database\Channel\PDO\PDOChannel
 */
abstract class Channel
{
  /**
   * 创建指定表的查询构建器
   *
   * @param string $table 表名
   * @param string $pk 主键字段名，默认 'id'
   * @return BaseQuery 查询构建器实例
   */
  public function table(string $table, string $pk = 'id'): BaseQuery
  {
    return new BaseQuery($this, $table, $pk);
  }

  /**
   * 执行SQL语句
   *
   * @param string|Raw $sql SQL语句或 Raw 对象
   * @param array $bindings 绑定参数
   * @param false|string $getId 传入字段名时返回该字段的自增ID，false 时不获取
   * @return mixed PDO 通道返回 PDOStatement 对象，其他通道返回执行结果
   * @throws DbException SQL执行失败时抛出
   */
  abstract public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false
  ): mixed;

  /**
   * 从连接池获取一个可用连接
   *
   * @param string $type 连接类型 read|write
   * @return mixed 数据库连接实例
   */
  abstract public function pop(string $type): mixed;

  /**
   * 归还连接到连接池，连接损坏时归还 null
   *
   * @param mixed $connect 数据库连接实例
   */
  abstract public function put(mixed $connect): void;

  /**
   * 根据查询选项构建SQL语句
   *
   * @param Options $options 查询选项
   * @return Raw 构建后的SQL表达式
   */
  abstract public function build(Options $options): Raw;
}
