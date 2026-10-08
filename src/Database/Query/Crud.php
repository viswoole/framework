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

declare(strict_types=1);

namespace Viswoole\Database\Query;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Cache\Facade\Cache;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\Collection;
use Viswoole\Database\Collection\BaseCollection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Entity;
use Viswoole\Database\Exception\DataNotFoundException;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Raw;

/**
 * CRUD 操作 Trait
 *
 * 提供增删改查、聚合查询、游标查询等数据库操作方法，
 * 通过 runCrud 统一入口执行，自动管理缓存、调试信息和查询重置。
 */
trait Crud
{
  /**
   * 插入数据，支持单条和批量写入
   *
   * @param array<string,mixed>|array<int,array<string,mixed>> $data 关联数组（单条）或索引数组（批量）
   * @return int|Raw 插入的记录数
   * @throws InvalidArgumentException $data为空时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function insert(array $data): int|Raw
  {
    if (empty($data)) throw new InvalidArgumentException('要写入数据不能为空');
    $this->options->data = $data;
    return $this->runCrud('insert');
  }

  /**
   * CRUD 操作统一入口，构建SQL并执行，自动管理缓存、调试信息和查询重置
   *
   * @param string $type 操作类型 insert|insertGetId|update|delete|select
   * @return Raw|string|array|int 查询结果
   * @throws DbException 数据库操作失败时抛出
   * @noinspection PhpDocRedundantThrowsInspection
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
   * 执行 SELECT 查询，支持缓存
   *
   * @param Raw $raw 构建后的SQL表达式
   * @return array 查询结果数组
   * @throws DbException 数据库操作失败时抛出
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
    // 缓存未命中：执行查询（移除原恒真的 `isset($result)` 死代码分支）
    /**
     * @var PDOStatement $statement
     */
    $statement = $this->channel->execute($raw->sql, $raw->bindings, false, $this->options->master);
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
    return $result;
  }

  /**
   * 执行查询（select 的别名方法）
   *
   * @param bool $allowEmpty 是否允许空结果
   * @return BaseCollection|Raw 查询结果集合（模型查询为 Collection，实体查询为 EntityCollection）
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws DbException 数据库操作失败时抛出
   * @see self::select()
   */
  public function get(bool $allowEmpty = true): BaseCollection|Raw
  {
    return $this->select($allowEmpty);
  }

  /**
   * 执行查询并返回集合
   *
   * @param bool $allowEmpty 是否允许空结果，为 false 且结果为空时抛出异常
   * @return BaseCollection|Raw 查询结果集合（模型查询为 Collection，实体查询为 EntityCollection）
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function select(bool $allowEmpty = true): BaseCollection|Raw
  {
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    if (empty($result) && !$allowEmpty) {
      throw new DataNotFoundException('未查询到数据', 0, $this->getLastQuery()->sql->sql);
    }
    return new Collection($this->newQuery(), $result);
  }

  /**
   * 执行写入操作（INSERT/UPDATE/DELETE），返回受影响行数或自增ID
   *
   * @param Raw $raw 构建后的SQL表达式
   * @param false|string $getId 传入字段名时返回自增ID，false 时不获取
   * @return string|int 受影响行数或自增ID
   * @throws DbException 数据库操作失败时抛出
   */
  protected function runWrite(Raw $raw, false|string $getId): string|int
  {
    $statement = $this->channel->execute(
      $raw->sql,
      $raw->bindings,
      $getId
    );
    if ($statement instanceof PDOStatementProxy || $statement instanceof PDOStatement) {
      $result = $statement->rowCount();
      $statement->closeCursor();
    } else {
      $result = $statement;
    }
    // 修复: 非自增表（雪花 ID）lastInsertId 恒返回 '0'——写入数据已含非空主键
    // （业务显式传入或 autoWritePk 生成）时，以写入值为准，避免覆盖
    if (
      $result === '0'
      && isset($this->options->data[$this->options->pk])
      && !empty($this->options->data[$this->options->pk])
    ) {
      $result = $this->options->data[$this->options->pk];
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
   * @return int|Raw 受影响的记录数
   * @throws DbException 数据库操作失败时抛出
   */
  public function delete(): int|Raw
  {
    return $this->runCrud('delete');
  }

  /**
   * 记录查询运行信息并保存调试日志、触发查询监听器
   *
   * @param float $start 查询开始时间（微秒）
   * @param Raw $raw 执行的SQL表达式
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
    // 读写路由由 PDO 通道在执行时写入协程上下文（按通道名隔离），非 PDO 通道为空串
    $route = (string)Context::get('$_db_query_route_' . $this->channel->name, '');
    $this->lastQuery = new RunInfo(
      $raw,
      $this->options->cache,
      $time,
      $this->channel->name,
      $route
    );
    // 保存
    Db::saveDebugInfo($this->lastQuery);
  }

  /**
   * 执行查询并以原始数组返回结果
   *
   * @return array|Raw 查询结果数组
   * @throws DbException 数据库操作失败时抛出
   */
  public function getArray(): array|Raw
  {
    return $this->runCrud('select');
  }

  /**
   * 判断是否不存在满足条件的数据（exists() 的取反快捷方法）
   *
   * @return bool 不存在返回 true
   * @throws DbException 数据库操作失败时抛出
   */
  public function doesntExist(): bool
  {
    return !$this->exists();
  }

  /**
   * 判断是否存在满足条件的数据
   *
   * 生成 SELECT 1 ... LIMIT 1 只取一行单列，命中即真，比 count() 全表计数高效。
   * ```
   * if (Db::table('user')->where('id', 1)->exists()) { ... }
   * ```
   * 注意：
   * - 若调用方已通过 columns() 设置查询列，SELECT 1 会叠加在其后（判空不受影响）；
   * - 缓存键不可与普通 select() 复用：exists 先执行会把单列哨兵行写入缓存，
   *   同 key 的 select() 命中后返回错误形态的数据；
   * - 模型查询中 with() 注册的关联不会被跳过，判空前会照常执行关联查询（浪费）。
   *
   * @return bool 存在返回 true
   * @throws DbException 数据库操作失败时抛出
   */
  public function exists(): bool
  {
    // exists() 语义是真实执行并判空，忽略调用方设置的 toRaw 标记
    $this->options->toRaw = false;
    // 单列常量列：避免 SELECT * 把宽行数据拉回内存
    $this->options->columns[] = Db::raw('1');
    $this->limit(1);
    $result = $this->runCrud('select');
    return !empty($result);
  }

  /**
   * 插入数据并返回自增主键值
   *
   * @param array $data 关联数组数据
   * @return string|int|Raw 自增主键值
   * @throws InvalidArgumentException $data非关联数组或为空时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function insertGetId(array $data): string|int|Raw
  {
    // 与 insert() 对齐的空数据守卫：空数组会生成 INSERT INTO t () VALUES ()，
    // MySQL 下静默插入全默认值记录（垃圾数据），必须在此拦截
    if (empty($data)) throw new InvalidArgumentException('要写入数据不能为空');
    if (!Arr::isAssociativeArray($data)) {
      throw new InvalidArgumentException('要写入的数据格式必须是关联数组');
    }
    $this->options->data = $data;
    return $this->runCrud('insertGetId');
  }

  /**
   * 字段原子自增（生成 col = col + ?），可同时写入其他字段
   *
   * 与 update() 一样需要 where 条件或数据中包含主键值；update_time 等模型
   * 自动写入逻辑照常生效。
   * ```
   * Db::table('user')->where('id', 1)->increment('login_count');
   * Db::table('user')->where('id', 1)->increment('score', 5, ['level' => 2]);
   * ```
   *
   * @param string $column 列名
   * @param int|float $amount 增量，默认 1，负值等价于减量
   * @param array<string,mixed|Raw> $extra 同时写入的其他字段（不能包含自增列名）
   * @return int|Raw 受影响的记录数
   * @throws InvalidArgumentException 列名包含非法字符、或 extra 中包含自增列名时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function increment(string $column, int|float $amount = 1, array $extra = []): int|Raw
  {
    return $this->incrementOrDecrement($column, '+', $amount, $extra);
  }

  /**
   * increment/decrement 统一实现：列名经白名单校验后内插表达式，增量走参数绑定
   *
   * @param string $column 列名
   * @param string $operator 算术运算符 +|-
   * @param int|float $amount 增/减量
   * @param array<string,mixed|Raw> $extra 同时写入的其他字段
   * @return int|Raw 受影响的记录数
   * @throws InvalidArgumentException 列名包含非法字符、或 extra 中包含自增列名时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  private function incrementOrDecrement(
    string    $column,
    string    $operator,
    int|float $amount,
    array     $extra
  ): int|Raw
  {
    // 表达式中的列名无法走 quote（Raw 原样拼接），与 aggregateQueries 同等白名单校验
    $column = trim($column);
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
      throw new InvalidArgumentException(
        "无效的自增字段：{$column}（仅允许字母、数字、下划线与点号）"
      );
    }
    // extra 同名键会整体覆盖自增表达式，原子语义静默丢失，显式拦截
    if (array_key_exists($column, $extra)) {
      throw new InvalidArgumentException(
        "increment/decrement 的 extra 数据不能包含自增列 $column"
      );
    }
    // 增量为占位符绑定参数，占位符位于 SET 子句，先于 WHERE 参数入列
    return $this->update(
      array_merge([$column => new Raw("$column $operator ?", [$amount])], $extra)
    );
  }

  /**
   * 更新记录
   *
   * @param array<string,mixed|Raw> $data 键值对，键为列名，值为新值（支持 Raw 表达式）
   * @return int|Raw 受影响的记录数
   * @throws InvalidArgumentException 数据为空时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function update(array $data): int|Raw
  {
    if (empty($data)) throw new InvalidArgumentException('要更新的数据不能为空');
    $this->options->data = $data;
    return $this->runCrud('update');
  }

  /**
   * 字段原子自减（生成 col = col - ?），可同时写入其他字段
   *
   * @param string $column 列名
   * @param int|float $amount 减量，默认 1，负值等价于增量
   * @param array<string,mixed|Raw> $extra 同时写入的其他字段（不能包含自增列名）
   * @return int|Raw 受影响的记录数
   * @throws InvalidArgumentException 列名包含非法字符、或 extra 中包含自增列名时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function decrement(string $column, int|float $amount = 1, array $extra = []): int|Raw
  {
    return $this->incrementOrDecrement($column, '-', $amount, $extra);
  }

  /**
   * 计算指定列非 NULL 的记录总数
   *
   * @param string $column 列名，默认 '*'
   * @return int|Raw 记录总数
   * @throws InvalidArgumentException 聚合字段包含非法字符时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function count(string $column = '*'): int|Raw
  {
    // 修复: __METHOD__ 会产生带命名空间的非法SQL函数名(如 Viswoole\...\Crud::count)，应使用简单方法名
    return $this->aggregateQueries('count', $column);
  }

  /**
   * 执行聚合查询（COUNT/MIN/MAX/AVG/SUM）
   *
   * 聚合列先做字符集校验再经 Raw 显式构建：聚合参数若被注入用户输入，
   * 无校验会直接拼入 SQL 构成注入；合法标识符（含 table.column 与 *）才放行
   *
   * @param string $type 聚合函数名
   * @param string $column 列名
   * @return mixed|Raw 聚合结果
   * @throws InvalidArgumentException 聚合字段包含非法字符时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  private function aggregateQueries(string $type, string $column): mixed
  {
    $fn = strtoupper($type);
    $column = trim($column);
    if ($column !== '*' && !preg_match('/^[A-Za-z0-9_.]+$/', $column)) {
      throw new InvalidArgumentException(
        "无效的聚合字段：{$column}（仅允许字母、数字、下划线与点号）"
      );
    }
    // 聚合表达式含函数语法，无法作为普通标识符 quote，经 Raw 显式构建
    $this->options->columns[] = Db::raw("$fn($column) AS $type");
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    return $result[0][$type];
  }

  /**
   * 返回指定列的第一个值，自动添加 LIMIT 1
   *
   * @param string $column 列名
   * @return mixed|false 列值，查询为空或列不存在时返回 false
   * @throws InvalidArgumentException 列不存在时抛出
   * @throws DbException 数据库操作失败时抛出
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
   * 获取指定列的最小值
   *
   * @param string $column 列名
   * @return string|int|float|Raw 最小值
   * @throws InvalidArgumentException 聚合字段包含非法字符时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function min(string $column): string|int|float|Raw
  {
    // 修复: __METHOD__ 会产生带命名空间的非法SQL函数名，应使用简单方法名
    return $this->aggregateQueries('min', $column);
  }

  /**
   * 获取指定列的最大值
   *
   * @param string $column 列名
   * @return string|int|float|Raw 最大值
   * @throws InvalidArgumentException 聚合字段包含非法字符时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function max(string $column): string|int|float|Raw
  {
    // 修复: __METHOD__ 会产生带命名空间的非法SQL函数名，应使用简单方法名
    return $this->aggregateQueries('max', $column);
  }

  /**
   * 获取指定列的平均值
   *
   * @param string $column 列名
   * @return float|int|Raw 平均值
   * @throws InvalidArgumentException 聚合字段包含非法字符时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function avg(string $column): float|int|Raw
  {
    // 修复: __METHOD__ 会产生带命名空间的非法SQL函数名，应使用简单方法名
    return $this->aggregateQueries('avg', $column);
  }

  /**
   * 获取指定列的总和
   *
   * @param string $column 列名
   * @return float|int|Raw 总和
   * @throws InvalidArgumentException 聚合字段包含非法字符时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function sum(string $column): float|int|Raw
  {
    // 修复: __METHOD__ 会产生带命名空间的非法SQL函数名，应使用简单方法名
    return $this->aggregateQueries('sum', $column);
  }

  /**
   * 查询单条记录
   *
   * @param bool $allowEmpty 是否允许空结果
   * @return DataSet|Entity|Raw|null 单行数据集（实体查询返回 Entity，空结果返回 null）
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws InvalidArgumentException 实体查询水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function first(bool $allowEmpty = true): DataSet|Entity|Raw|null
  {
    return $this->find(null, $allowEmpty);
  }

  /**
   * 按主键查询单条记录，自动添加 LIMIT 1
   *
   * 与 select() 不同，find() 返回单行数据载体（模型为 DataSet、实体查询为实体），
   * 可直接修改字段值并调用 save() 持久化；空结果且允许空时返回 null。
   *
   * @param int|string|null $value 主键值，为 null 时需配合 where 条件
   * @param bool $allowEmpty 是否允许空结果，为 false 且结果为空时抛出异常
   * @return DataSet|Entity|Raw|null 单行数据集（实体查询返回 Entity），
   *   空结果且允许空时返回 null
   * @throws DataNotFoundException 查询为空且不允许空时抛出
   * @throws InvalidArgumentException 实体查询水合失败（行数据与属性类型不匹配）时抛出
   * @throws RuntimeException 实体属性声明不支持的字段类型或 readonly 时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function find(int|string|null $value = null, bool $allowEmpty = true
  ): DataSet|Entity|Raw|null
  {
    $this->limit(1);
    // 修复: 使用严格比较 === null 判断，避免主键值为 0 时被 empty() 误判为空导致无法查询
    if ($value !== null) $this->where($this->options->pk, $value);
    $result = $this->runCrud('select');
    if ($result instanceof Raw) return $result;
    // 空结果返回 null 而非空 DataSet：空 DataSet 是无主键的"死对象"，
    // 对其 save()/delete() 只能抛异常，语义上未命中就该是 null
    if (empty($result)) {
      if (!$allowEmpty) {
        throw new DataNotFoundException('未查询到数据', 0, $this->getLastQuery()->sql->sql);
      }
      return null;
    }
    return $this->newRowSet($result[0]);
  }

  /**
   * 游标查询，逐条返回数据，适用于大数据量场景
   *
   * 不适用缓存，因为缓存大量数据仍会造成内存溢出。
   *
   * @return Generator 逐条返回 DataSet 的生成器
   * @throws DbException 数据库操作失败时抛出
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
    $statement = $this->channel->execute($raw, master: $this->options->master);
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
   * 分段查询，每次读取指定数量的数据，适用于大数据量分批处理
   *
   * @param int $size 每次读取的记录数
   * @return Generator 逐批返回 Collection 的生成器
   * @throws DbException 数据库操作失败时抛出
   */
  public function chunk(int $size): Generator
  {
    try {
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
        $statement = $this->channel->execute($raw, master: $this->options->master);
        $results = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        $this->setRunInfo($start, $raw);
        if (empty($results)) break;
        yield new Collection($this->newQuery(), $results);
        $offset += $size;
      }
    } finally {
      // 无论正常耗尽、中途 break 还是生成器被废弃（GC 销毁），
      // 都保证查询条件重置，避免 LIMIT/OFFSET 残留污染后续查询
      $this->reset();
    }
  }
}
