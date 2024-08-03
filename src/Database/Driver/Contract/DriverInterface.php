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


use Viswoole\Database\Collector\QueryOptions;
use Viswoole\Database\DataSet\DataSetCollection;
use Viswoole\Database\DataSet\Row;

interface DriverInterface
{
  /**
   * 数据库表统一前缀
   *
   * @param string|null $table 表名
   * @return string 如果传入表名则返回表前缀+表名 否则返回表前缀
   */
  public function prefix(string $table = null): string;

  /**
   * 打包sql
   *
   * @param QueryOptions $options 查询构造器选项
   * @return string|array{sql:string,params:array} 如果合并sql则返回字符串 否则返回['sql'=>string,'params'=>array]
   */
  public function builder(QueryOptions $options): string|array;

  /**
   * 原生查询
   *
   * @param string $sql
   * @param array $params
   * @return DataSetCollection|Row
   */
  public function query(
    string $sql,
    array  $params = []
  ): DataSetCollection|Row;

  /**
   * 原生写入
   *
   * @param string $sql
   * @param array $params
   * @return int
   */
  public function execute(string $sql, array $params = []): int;

  /**
   * 原生执行sql
   *
   * @param string $sql
   * @param array $params
   * @return mixed 返回原生的执行结果，例如PDO驱动则返回PDOStatement对象
   */
  public function exec(string $sql, array $params = []): mixed;
}
