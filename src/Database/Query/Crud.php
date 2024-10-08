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

use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Cache\Facade\Cache;
use Viswoole\Core\Common\Arr;
use Viswoole\Database\Collection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Exception\DataNotFoundException;
use Viswoole\Database\Exception\DbException;
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
   * 示例：
   * ```
   * // 要写入的用户信息
   * $userInfo = [
   *    'name'=>'张三',
   *    'age'=>18
   * ];
   * // 写入单条记录示例
   * $result = $query->insert($userInfo);
   * print_r('写入数据结果：'.$result>0?true:false);
   * // 写入多条记录示例
   * $result = $query->insert([$userInfo,$userInfo])
   * print_r("成功创建数据：$result 条");
   * ```
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
   * @return Raw|string|array|int
   */
  protected function runCrud(string $type): Raw|string|array|int
  {
    $start = microtime(true);
    $this->options->type = $type;
    $raw = $this->channel->build($this->options);
    try {
      if ($this->options->toRaw) {
        $result = $raw;
      } else {
        // 查询方法
        if ($type === 'select') {
          $result = $this->runSelect($raw);
        } else {
          $result = $this->runWrite($raw, $type === 'insertGetId' ? $this->options->pk : false);
        }
      }
      $this->setRunInfo($start, $raw);
      return $result;
    } finally {
      $this->reset();
    }
  }

  /**
   * 执行查询
   *
   * @param Raw $raw
   * @return array
   * @throws DbException
   */
  protected function runSelect(Raw $raw): array
  {
    $cacheStore = null;
    if ($this->options->cache) {
      $cacheStore = Cache::store($this->options->cache['store']);
      if ($cacheStore->has($this->options->cache['key'])) {
        return $cacheStore->get($this->options->cache['key']);
      }
    }
    if (!isset($result)) {
      /**
       * @var PDOStatement $statement
       */
      $statement = $this->channel->execute($raw->sql, $raw->bindings);
      // 获取查询结果
      $result = $statement->fetchAll(PDO::FETCH_ASSOC);
      $statement->closeCursor();
      if ($cacheStore) {
        if ($this->options->cache['tag']) {
          $cacheStore = $cacheStore->tag($this->options->cache['tag']);
        }
        // 写入缓存
        $cacheStore->set($this->options->cache['key'], $result, $this->options->cache['expire']);
      }
    }
    return $result;
  }

  /**
   * 执行查询
   *
   * 该方法和select方法相同
   *
   * @param bool $allowEmpty
   * @return Collection|Raw
   * @throws DataNotFoundException 如果查询结果为空，且allowEmpty为false，则抛出异常。
   * @throws DbException 数据库抛出的异常
   * @see self::select()
   */
  public function get(bool $allowEmpty = true): Collection|Raw
  {
    return $this->select($allowEmpty);
  }

  /**
   * 执行查询，并返回查询结果
   *
   * @param bool $allowEmpty 是否允许为空。
   * @return Collection|Raw
   * @throws DataNotFoundException 如果查询结果为空，且allowEmpty为false，则抛出异常。
   * @throws DbException
   */
  public function select(bool $allowEmpty = true): Collection|Raw
  {
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    if (empty($result) && !$allowEmpty) {
      throw new DataNotFoundException('未查询到数据', 0, $this->getLastQuery()->sql->toString());
    }
    return new Collection($this->newQuery(), $result);
  }

  /**
   * @param Raw $raw
   * @param false|string $getId
   * @return string|int
   * @throws DbException
   */
  protected function runWrite(Raw $raw, false|string $getId): string|int
  {
    $statement = $this->channel->execute(
      $raw->sql, $raw->bindings, $getId
    );
    if ($statement instanceof PDOStatementProxy || $statement instanceof PDOStatement) {
      $result = $statement->rowCount();
      $statement->closeCursor();
    } else {
      $result = $statement;
    }
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
   * 执行查询，并以数组返回查询结果。
   *
   * @return array|Raw
   */
  public function getArray(): array|Raw
  {
    return $this->runCrud('select');
  }

  /**
   * 插入数据，返回主键值
   *
   * @param array $data
   * @return string|int|Raw 写入成功返回主键值
   */
  public function insertGetId(array $data): string|int|Raw
  {
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
   * @throws DbException
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
   * @return mixed|false 如果查询结果为空，则返回false。
   */
  public function value(string $column): mixed
  {
    $this->limit(1);
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    $data = $result[0] ?? [];
    if (empty($data)) return false;
    if (!array_key_exists($column, $data)) {
      throw new InvalidArgumentException("查询结果中不存在指定的列名：$column");
    }
    return $result[0][$column];
  }

  /**
   * 获取最小值。
   *
   * @param string $column 列名。
   * @return string|int|float|Raw 最小值。
   * @throws DbException
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
   * @throws DbException
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
   * @throws DbException
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
   * @throws DbException
   */
  public function sum(string $column): float|int|Raw
  {
    return $this->aggregateQueries(__METHOD__, $column);
  }

  /**
   * 查询单条记录，并返回DataSet对象
   *
   * @param bool $allowEmpty
   * @return DataSet|Raw
   * @throws DataNotFoundException
   * @throws DbException
   */
  public function first(bool $allowEmpty = true): DataSet|Raw
  {
    return $this->find(null, $allowEmpty);
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
   * // 上面的查询生成的sql等效于下一行查询,唯一不同的是find方法会直接返回DataSet对象，可以快捷操作当前数据
   * // $user = Db::table('users','id')->where('id',1)->limit(1)->select();
   * // 可支持同时使用where条件
   * // $user = Db::table('users','id')->where('status',1)->find(1);
   * // 修改用户名
   * $user->name = 'John';// $user['name'] = 'John' 两种语法都可以使用
   * // 保存修改
   * $user->save();
   * ```
   *
   * @param int|string|null $value 主键值
   * @param bool $allowEmpty 是否允许为空
   * @return DataSet|Raw
   * @throws DataNotFoundException 如果查询结果为空，且allowEmpty为false，则抛出异常。
   * @throws DbException
   */
  public function find(int|string $value = null, bool $allowEmpty = true): DataSet|Raw
  {
    $this->limit(1);
    if (!empty($value)) $this->where($this->options->pk, $value);
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    if (empty($result) && !$allowEmpty) {
      throw new DataNotFoundException('未查询到数据', 0, $this->getLastQuery()->sql->toString());
    }
    return new DataSet($this->newQuery(), $result[0] ?? []);
  }

  /**
   * 游标查询
   *
   * 该方法和select()方法类似，不同之处在于，该方法返回的是一个生成器，可以逐条读取数据。
   *
   * 该方法不适用cache，因为缓存大量数据依旧会造成内存溢出。
   *
   * @return Generator 返回生成器
   * @throws DbException
   */
  public function cursor(): Generator
  {
    $start = microtime(true);
    // 关闭缓存功能
    $this->options->cache = false;
    // 设置查询类型
    $this->options->type = 'select';
    // 打包SQL
    $raw = $this->channel->build($this->options);
    /**
     * @var PDOStatement $statement
     */
    $statement = $this->channel->execute($raw);
    // 保存执行信息
    $this->setRunInfo($start, $raw);
    // 重置查询参数
    $this->reset();
    // 返回生成器
    while ($result = $statement->fetch(PDO::FETCH_ASSOC)) {
      yield new DataSet($this->newQuery(), $result);
    }
    // 关闭PDOStatement
    $statement->closeCursor();
  }

  /**
   * 分段查询
   *
   * @param int $size 每次读取的数量
   * @return Generator 返回生成器
   * @throws DbException
   */
  public function chunk(int $size): Generator
  {
    // 关闭缓存
    $this->options->cache = false;
    $this->options->type = 'select';
    $this->limit($size);
    // 偏移量
    $offset = $this->options->offset ?? 0;
    $this->offset($offset);
    // 打包SQL
    $raw = $this->channel->build($this->options);
    while (true) {
      $start = microtime(true);
      // 替换 OFFSET 的值
      $raw->sql = preg_replace('/OFFSET\s+\d+/', "OFFSET $offset", $raw->sql);
      /**
       * @var PDOStatement $statement
       */
      $statement = $this->channel->execute($raw);
      $results = $statement->fetchAll(PDO::FETCH_ASSOC);
      $statement->closeCursor();
      $this->setRunInfo($start, $raw);
      if (empty($results)) break;
      yield new Collection($this->newQuery(), $results);
      $offset += $size;
    }
    $this->reset();
  }
}
