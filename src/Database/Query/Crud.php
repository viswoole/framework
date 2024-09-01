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
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Facade\Db;
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
    if (empty($data)) throw new InvalidArgumentException('要写入数据不能为空');
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
    $raw = $this->channel->build($this->options);
    $start = microtime(true);
    if ($this->options->toRaw) {
      $result = $raw;
    } else {
      // 查询方法
      if ($type === 'select') {
        if ($this->options->cache) {
          $result = Cache::store($this->options->cache['store'])->get($this->options->cache['key']);
        } else {
          $result = $this->channel->query($raw->sql, $raw->bindings);
          if ($this->options->cache) {
            $cache = Cache::store($this->options->cache['store']);
            if ($this->options->cache['tag']) $cache = $cache->tag($this->options->cache['tag']);
            $cache->set($this->options->cache['key'], $result, $this->options->cache['expire']);
          }
        }
      } else {
        $result = $this->channel->execute(
          $raw->sql, $raw->bindings, $type === 'insertGetId'
        );
        // 删除缓存
        if ($this->options->cache) {
          if ($this->options->cache['tag']) {
            Cache::store($this->options->cache['store'])
                 ->tag($this->options->cache['tag'])
                 ->remove($this->options->cache['key']);
          } else {
            Cache::store($this->options->cache['store'])
                 ->delete($this->options->cache['key']);
          }
        }
      }
    }
    $this->setRunInfo($start, $raw);
    $this->reset();
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
   * 获取sql运行时间
   *
   * @param float $start
   * @param Raw $raw
   * @return void
   */
  private function setRunInfo(float $start, Raw $raw): void
  {
    $end = microtime(true);
    // 间隔了多少秒
    $interval = $end - $start;
    // 执行时间秒
    $executionTime = round($interval, 6);
    // 计算执行时间毫秒
    $executionTimeMilliseconds = round($interval * 1000); // 执行时间（毫秒）
    $time = [
      'start_time' => $start,
      'end_time' => $end,
      'cost_time_s' => $executionTime,
      'cost_time_ms' => $executionTimeMilliseconds
    ];
    $this->lastQuery = new RunInfo($raw, $this->options->cache, $time);
    // 保存
    Db::saveDebugInfo($this->lastQuery);
  }

  /**
   * 插入数据，并返回自增ID
   *
   * @param array $data
   * @return string|int|array|Raw 返回插入的ID, 如果插入多条数据，则返回数组，元素为插入的ID。
   */
  public function insertGetId(array $data): string|int|array|Raw
  {
    if (empty($data)) throw new InvalidArgumentException('要写入数据不能为空');
    if (!Arr::isAssociativeArray($data)) {
      throw new InvalidArgumentException('要写入的数据格式必须是关联数组');
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
    if (empty($data)) throw new InvalidArgumentException('要更新的数据不能为空');
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
    return $this->aggregateQueries(__METHOD__, $column);
  }

  /**
   * 聚合查询
   *
   * @param string $type
   * @param string $column
   * @return mixed|Raw
   */
  private function aggregateQueries(string $type, string $column): mixed
  {
    $fn = strtoupper($type);
    $this->columns("$fn($column) AS $type");
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    return $result[0][$type];
  }

  /**
   * 返回某个字段的值
   *
   * 该方法会自动添加上limit = 1
   *
   * @param string $column
   * @return mixed 如果查询结果为空，则返回null。
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
    return $this->aggregateQueries(__METHOD__, $column);
  }

  /**
   * 获取最大值。
   *
   * @param string $column 列名。
   * @return string|int|float|Raw 最大值。
   */
  public function max(string $column): string|int|float|Raw
  {
    return $this->aggregateQueries(__METHOD__, $column);
  }

  /**
   * 获取平均值。
   *
   * @param string $column 列名。
   * @return float|int|Raw 平均值。
   */
  public function avg(string $column): float|int|Raw
  {
    return $this->aggregateQueries(__METHOD__, $column);
  }

  /**
   * 获取总和。
   *
   * @param string $column 列名。
   * @return float|int|Raw 总和。
   */
  public function sum(string $column): float|int|Raw
  {
    return $this->aggregateQueries(__METHOD__, $column);
  }

  /**
   * 查询单条记录
   *
   * 和select()方法不同，find()方法会自动添加上limit = 1，
   * 且返回的是Row对象，可以直接通过属性方式获取/修改字段值。支持save()方法直接保存修改过后的数据。
   *
   * 示例:
   * ```
   * // 查询id为1的用户
   * $user = Db::table('users','id')->find(1);
   * // 上面的查询生成的sql等效于下一行查询,唯一不同的是find方法会直接返回Row对象，可以快捷操作当前数据
   * // $user = Db::table('users','id')->where('id',1)->limit(1)->select();
   * // 可支持同时使用where条件
   * // $user = Db::table('users','id')->where('status',1)->find(1);
   * // 修改用户名
   * $user->name = 'John';// $user['name'] = 'John' 两种语法都可以使用
   * // 保存修改
   * $user->save();
   *
   * ```
   *
   * @param int|string|null $value 主键值
   * @return DataSet|Raw
   */
  public function find(int|string $value = null): DataSet|Raw
  {
    $this->limit(1);
    if (!empty($value)) $this->where($this->options->pk, $value);
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    return new DataSet($this->newQuery(), $result[0] ?? []);
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
    return new Collection($this->newQuery(), $result);
  }
}
