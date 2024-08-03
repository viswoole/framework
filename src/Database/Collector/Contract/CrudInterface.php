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

use Viswoole\Database\DataSet\DataSetCollection;
use Viswoole\Database\DataSet\Row;

/**
 * 查询方式
 */
interface CrudInterface
{
  /**
   * 更新
   *
   * @access public
   * @param array<string,mixed> $data 关联数组[字段=>值...]，如果调用了inc或dec方法则可不传入数据
   * @return int
   */
  public function update(array $data = null): int;

  /**
   * 删除数据
   *
   * @param int|string|array|null $pks 主键,支持数组
   * @return int
   */
  public function delete(int|string|array $pks = null): int;

  /**
   * 新增数据
   *
   * @param array<string,mixed>|array<int,array<string,mixed>> $data 关联数组[字段=>值]或[[字段=>值...],[字段=>值]...]
   * @param bool $getId 是否获取写入成功数据的主键。
   * @return int|string|array 如果$getId为true，返回主键值，否则返回受影响的行数，如果插入多行数据且$getId=true则返回主键值数组
   */
  public function insert(array $data = null, bool $getId = false): int|string|array;

  /**
   * 查询单条数据
   *
   * @access public
   * @param int|string|null $pk 主键值,等同于where('$pk',$pk)
   * @return Row
   */
  public function find(int|string $pk = null): Row;

  /**
   * 查询数据集
   *
   * @access public
   * @param array|null $pks 要查询的主键，等同于whereIn('$pk',$pks)
   * @return DataSetCollection 数据集合
   */
  public function select(array $pks = null): DataSetCollection;

  /**
   * 统计查询
   *
   * @param string $column 需要统计的列，默认为*，*代表统计表的记录数量
   * @return int
   */
  public function count(string $column = '*'): int;

  /**
   * 聚合指定列的值
   *
   * @param string $column
   * @return int
   */
  public function sum(string $column): int;

  /**
   * 返回指定列平均值
   *
   * @param string $column
   * @return int
   */
  public function avg(string $column): int;

  /**
   * 返回指定列最小值
   *
   * @param string $column 字段名称
   * @return int|string 返回值类型取决于字段类型
   */
  public function min(string $column): int|string;

  /**
   * 返回指定列最大值
   *
   * @param string $column 字段名称
   * @return int|string 返回值类型取决于字段类型
   */
  public function max(string $column): int|string;
}
