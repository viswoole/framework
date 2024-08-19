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

use Viswoole\Cache\Facade\Cache;
use Viswoole\Database\Collector\CrudMethod;
use Viswoole\Database\DataSet\DataSetCollection;
use Viswoole\Database\DataSet\Row;

/**
 * CRUD操作
 */
trait CrudTrait
{
  const array READ_METHODS = ['COUNT', 'SUM', 'MIN', 'MAX', 'AVG', 'SELECT', 'FIND'];

  /**
   * 更新
   *
   * @access public
   * @param array<string,mixed> $data 关联数组[字段=>值...],如果使用了inc或dec方法则可不传
   * @return int
   */
  public function update(array $data = null): int
  {
    if (!empty($data)) $this->options->data = $data;
    return $this->runCrud(CrudMethod::UPDATE);
  }

  /**
   * 运行CRUD操作。
   *
   * @param CrudMethod $method CRUD操作方法。
   * @return mixed 根据不同的CRUD操作返回不同的结果。
   */
  protected function runCrud(CrudMethod $method): mixed
  {
    $options = $this->options;
    $isQuery = in_array($method->name, self::READ_METHODS);
    $cache = $options->cache;
    if ($isQuery) {
      if ($options->cache) {
        $result = Cache::store($options->cache['store'])->get($options->cache['key']);
      }
      if (!isset($result)) {
        $sql = $this->build($method, false);
        $result = $this->driver->query($sql['sql'], $sql['params']);
      }
    } else {
      if ($cache['tag']) {
        Cache::store($options->cache['store'])->tag($cache['tag'])->remove($cache['key']);
      } else {
        Cache::store($options->cache['store'])->delete($options->cache['key']);
      }
    }
    return '';
  }

  /**
   * 删除数据
   *
   * @param int|string|array|null $pks 主键,支持数组
   * @return int
   */
  public function delete(int|array|string $pks = null): int
  {
    if (!is_null($pks)) {
      if (is_array($pks)) {
        $this->whereIn($this->pk, $pks);
      } else {
        $this->whereEq($this->pk, $pks);
      }
    }
    return $this->runCrud(CrudMethod::DELETE);
  }

  /**
   * 创建新记录
   *
   * @param array<string,mixed>|array<int,array<string,mixed>> $data 关联数组[字段=>值]或[[字段=>值...],[字段=>值]...]
   * @param bool $getId 是否获取写入成功数据的主键。 默认为false
   * @return int|string|array 如果$getId为true则返回主键值，否则返回受影响的行数，如果插入多行数据且$getId=true则返回主键值数组
   */
  public function create(array $data = null, bool $getId = false): int|string|array
  {
    if (!empty($data)) $this->options->data = $data;
    $this->options->insertGetId = $getId;
    return $this->runCrud(CrudMethod::CREATE);
  }

  /**
   * 查询单条数据
   *
   * @access public
   * @param int|string|null $pk 主键值,等同于where('$pk',$pk)
   * @return Row
   */
  public function find(int|string $pk = null): Row
  {
    if (!is_null($pk)) $this->whereEq($this->pk, $pk);
    return $this->runCrud(CrudMethod::FIND);
  }

  /**
   * 查询数据集
   *
   * @access public
   * @param array|null $pks 要查询的主键，等同于whereIn('$pk',$pks)
   * @return DataSetCollection 数据集合
   */
  public function select(array $pks = null): DataSetCollection
  {
    if (is_array($pks)) {
      if (count($pks) === 1) {
        $this->whereEq($this->pk, $pks);
      } else {
        $this->whereIn($this->pk, $pks);
      }
    }
    return $this->runCrud(CrudMethod::SELECT);
  }

  /**
   * 统计查询
   *
   * @param string $column 需要统计的列，默认为*，*代表统计表的记录数量
   * @return int
   */
  public function count(string $column = '*'): int
  {
    $this->options->columnName = $column;
    return $this->runCrud(CrudMethod::COUNT);
  }

  /**
   * 聚合指定列的值
   *
   * @param string $column
   * @return int
   */
  public function sum(string $column): int
  {
    $this->options->columnName = $column;
    return $this->runCrud(CrudMethod::SUM);
  }

  /**
   * 返回指定列平均值
   *
   * @param string $column
   * @return int
   */
  public function avg(string $column): int
  {
    $this->options->columnName = $column;
    return $this->runCrud(CrudMethod::AVG);
  }

  /**
   * 返回指定列最小值
   *
   * @param string $column 字段名称
   * @return int|string 返回值类型取决于字段类型
   */
  public function min(string $column): int|string
  {
    $this->options->columnName = $column;
    return $this->runCrud(CrudMethod::MIN);
  }

  /**
   * 返回指定列最大值
   *
   * @param string $column 字段名称
   * @return int|string 返回值类型取决于字段类型
   */
  public function max(string $column): int|string
  {
    $this->options->columnName = $column;
    return $this->runCrud(CrudMethod::MAX);
  }
}
