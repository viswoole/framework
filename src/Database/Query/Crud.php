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

namespace Viswoole\Database\Query;

use InvalidArgumentException;
use Viswoole\Cache\Facade\Cache;
use Viswoole\Core\Common\Arr;
use Viswoole\Database\Collection;
use Viswoole\Database\Raw;

/**
 * CRUD操作
 */
trait Crud
{
  /**
   * 插入数据
   *
   * @param array<string,mixed>|array<int,array<string,mixed>> $data 要插入的数据
   * @return int|Raw 返回插入的记录数
   */
  public function insert(array $data): int|Raw
  {
    $this->options->data = $data;
    return $this->runCrud('insert');
  }

  /**
   * @param string $type
   * @return mixed
   */
  protected function runCrud(string $type): mixed
  {
    $this->options->type = $type;
    $options = $this->options;
    if ($this->options->getSql) {
      return $this->channel->build($options);
    }
    // 查询方法
    if ($type === 'select') {
      if ($options->cache) {
        $result = Cache::store($options->cache['store'])->get($options->cache['key']);
        if ($result) return $result;
      }
      if (!isset($result)) {
        $sql = $this->channel->build($options);
        $result = $this->channel->query($sql->sql, $sql->bindings);
        if ($options->cache) {
          $cacheData = $result;
          $cache = Cache::store($options->cache['store']);
          if ($options->cache['tag']) $cache = $cache->tag($options->cache['tag']);
          $cache->set($options->cache['key'], $cacheData, $options->cache['expire']);
        }
        return $result;
      }
    } else {
      $sql = $this->channel->build($options);
      $result = $this->channel->execute($sql->sql, $sql->bindings, $type === 'insertGetId');
      // 删除缓存
      if ($options->cache) {
        if ($options->cache['tag']) {
          Cache::store($options->cache['store'])
               ->tag($options->cache['tag'])
               ->remove($options->cache['key']);
        } else {
          Cache::store($options->cache['store'])
               ->delete($options->cache['key']);
        }
      }
    }
    return $result;
  }

  /**
   * 删除记录
   *
   * @return int|Raw
   */
  public function delete(): int|Raw
  {
    return $this->runCrud('delete');
  }

  /**
   * 插入数据，并返回自增ID
   *
   * @param array $data
   * @return string|int|array|Raw 返回插入的ID, 如果插入多条数据，则返回数组，元素为插入的ID。
   */
  public function insertGetId(array $data): string|int|array|Raw
  {
    if (!Arr::isAssociativeArray($data)) {
      throw new InvalidArgumentException('The data must be an associative array.');
    }
    $this->options->data = $data;
    return $this->runCrud('insertGetId');
  }

  /**
   * 更新记录
   *
   * @param array<string,mixed|Raw> $data 键值对数组，键为列名，值为要更新的值
   * @return int|Raw 返回更新的记录数
   */
  public function update(array $data): int|Raw
  {
    $this->options->data = $data;
    return $this->runCrud('update');
  }

  /**
   * 计算指定列不能为null的记录总数
   *
   * @param string $column 列名。
   * @return int|Raw
   */
  public function count(string $column = '*'): int|Raw
  {
    $this->columns("COUNT($column) AS count");
    $this->options->withoutColumns = [];
    return $this->value('count');
  }

  /**
   * 只查看某一列的值
   *
   * @param string $column
   * @return mixed
   */
  public function value(string $column): mixed
  {
    $this->limit(1);
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    return $result[0][$column] ?? null;
  }

  /**
   * 获取最小值。
   *
   * @param string $column 列名。
   * @return string|int|float|Raw 最小值。
   */
  public function min(string $column): string|int|float|Raw
  {
    $this->columns("MIN($column) AS min");
    $this->options->withoutColumns = [];
    return $this->value('min');
  }

  /**
   * 获取最大值。
   *
   * @param string $column 列名。
   * @return string|int|float|Raw 最大值。
   */
  public function max(string $column): string|int|float|Raw
  {
    $this->columns("MAX($column) AS max");
    $this->options->withoutColumns = [];
    return $this->value('max');
  }

  /**
   * 获取平均值。
   *
   * @param string $column 列名。
   * @return float|Raw 平均值。
   */
  public function avg(string $column): float|Raw
  {
    $this->columns("AVG($column) AS avg");
    $this->options->withoutColumns = [];
    return $this->value('avg');
  }

  /**
   * 获取总和。
   *
   * @param string $column 列名。
   * @return float|Raw 总和。
   */
  public function sum(string $column): float|Raw
  {
    $this->columns("SUM($column) AS sum");
    $result = $this->find();
    if ($result instanceof Raw) return $result;
    return $result->sum;
  }

  /**
   * 查询单条记录
   *
   * @param int|string|null $id 主键ID
   * @return Collection|Raw
   */
  public function find(int|string $id = null): Collection|Raw
  {
    $this->limit(1);
    if (!empty($id)) $this->where($this->options->pk, $id);
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    return new Collection($this->channel, $this->options, $result[0] ?? []);
  }

  /**
   * 执行查询，并返回查询结果
   *
   * @return Collection|Raw
   */
  public function select(): Collection|Raw
  {
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    return new Collection($this->channel, $this->options, $result);
  }
}
