<?php /*
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
/** @noinspection PhpUndefinedFieldInspection */
declare (strict_types=1);

namespace Viswoole\Database\Model;

use Closure;
use InvalidArgumentException;
use Override;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Throwable;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Common\Str;
use Viswoole\Database\BaseQuery as BaseQuery;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Model;
use Viswoole\Database\Raw;

/**
 * 模型查询构造器
 *
 * 扩展 BaseQuery，增加软删除、时间戳自动写入、关联查询、获取器等 ORM 能力。
 * 通过模型属性配置元信息，自动在 CRUD 操作前后注入业务逻辑。
 *
 * @see BaseQuery
 * @see Model
 */
class Query extends BaseQuery
{
  /** @var bool 查询结果是否包含软删除的数据 */
  private bool $withTrashed = false;
  /** @var array<string,RelationQuery> 已注册的关联查询，键为关联名称 */
  private array $relations = [];

  /**
   * 初始化模型查询实例，从模型属性读取表名、主键和通道配置
   *
   * @param Model $model 关联的模型实例
   */
  public function __construct(protected Model $model)
  {
    parent::__construct(
      Db::channel($this->channelName),
      $this->table, $this->pk
    );
  }

  /**
   * 克隆时重置软删除和关联查询状态
   */
  public function __clone(): void
  {
    parent::__clone();
    $this->withTrashed = false;
    $this->relations = [];
  }

  /**
   * 注册关联查询，支持闭包对关联查询添加额外条件
   *
   * @param array<string,Closure>|string[]|string $relations 关联名称、关联名称数组或"关联名=>回调"映射
   * @return static 支持链式调用
   * @throws InvalidArgumentException 关联方法不存在或返回值非 RelationQuery 时抛出
   */
  public function with(array|string $relations): static
  {
    if (is_string($relations)) $relations = [$relations];
    foreach ($relations as $key => $callback) {
      if (is_int($key)) {
        $key = $callback;
        $callback = null;
      }
      if (!method_exists($this->model, $key)) {
        $class = get_class($this->model);
        throw new InvalidArgumentException("$class::$key() 方法不存在");
      }
      /**
       * @var RelationQuery $instance 关联查询实例
       */
      $instance = $this->model->$key();
      if (!$instance instanceof RelationQuery) {
        $class = get_class($this->model);
        $relationQuery = RelationQuery::class;
        throw new InvalidArgumentException(
          "$class::$key() 方法返回值必须是{$relationQuery}实例，才能进行关联查询。"
        );
      }
      if (is_callable($callback)) $instance->handle($callback);
      // 添加关联查询
      $this->relations[$key] = $instance;
    }
    return $this;
  }

  /**
   * 代理访问模型属性
   *
   * @param string $name 属性名
   * @return mixed 属性值
   */
  public function __get(string $name)
  {
    return $this->model->__properties($name);
  }

  /**
   * 删除记录，启用软删除时执行软删除而非物理删除
   *
   * @param bool $real 是否硬删除，仅启用软删除时有效
   * @return int|Raw 受影响的记录数或 Raw 对象
   * @throws DbException 数据库操作失败时抛出
   */
  #[Override]
  public function delete(bool $real = false): int|Raw
  {
    if ($this->enableSoftDelete && !$real) {
      return parent::update([
        $this->softDeleteFieldName => $this->_getTime($this->softDeleteFieldType)
      ]);
    }
    return parent::delete();
  }

  /**
   * 根据格式类型获取当前时间字符串
   *
   * @param string $format 时间格式类型 datetime|timestamp|date|int
   * @return string 格式化后的时间字符串
   */
  private function _getTime(string $format): string
  {
    // 修复#6: 将所有分支返回值统一转为string，与返回类型声明一致
    return match ($format) {
      'datetime' => date('Y-m-d H:i:s'),
      'timestamp', 'time' => (string)time(),
      'date' => date('Y-m-d'),
      'int' => '1',
      default => date($format)
    };
  }

  /**
   * 恢复软删除的数据
   *
   * @param int|string|array|null $id 要恢复记录的主键值，为空时需指定 where 条件
   * @return int|Raw 受影响的记录数或 Raw 对象，未启用软删除时返回 0
   * @throws RuntimeException 未启用软删除或未指定条件时抛出
   * @throws DbException 数据库操作失败时抛出
   */
  public function restore(int|string|array|null $id = null): int|Raw
  {
    // 未启动软删除功能
    if (!$this->enableSoftDelete) return 0;
    // 指定主键
    if (!empty($id)) $this->where($this->pk, $id);
    // 没有条件
    if (empty($this->options->where)) {
      throw new RuntimeException('Model::restore() 必须指定要恢复记录的主键值或设置where条件');
    }
    return $this->update([
      $this->softDeleteFieldName => $this->softDeleteFieldDefaultValue
    ]);
  }

  /**
   * 获取模型定义的隐藏字段列表
   *
   * @return array 隐藏字段名数组
   */
  public function getHiddenColumn(): array
  {
    return $this->hidden;
  }

  /**
   * 设置查询结果是否包含软删除的数据
   *
   * @param bool $withTrashed 是否包含，默认 true
   * @return static 支持链式调用
   */
  public function withTrashed(bool $withTrashed = true): static
  {
    $this->withTrashed = $withTrashed;
    return $this;
  }

  /**
   * 应用模型获取器，将蛇形字段名转换为驼峰后查找对应的 get{Field}Attr 方法
   *
   * @param string $key 字段名
   * @param mixed $value 原始值
   * @return mixed 转换后的值，无获取器时返回原值
   */
  public function withGetAttr(string $key, mixed $value): mixed
  {
    $key = Str::snakeCaseToCamelCase($key);
    if (method_exists($this->model, "get{$key}Attr")) {
      return call_user_func([$this->model, "get{$key}Attr"], $value);
    } else {
      return $value;
    }
  }

  /**
   * 重置查询选项及软删除、关联查询状态
   *
   * @return static 支持链式调用
   */
  public function reset(): static
  {
    $this->withTrashed = false;
    return parent::reset();
  }

  /**
   * 将方法调用转发到模型层，用于访问模型自定义方法
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed 模型方法的返回值
   * @throws RuntimeException 模型方法不存在时抛出
   */
  public function __call(string $name, array $arguments)
  {
    if (!method_exists($this->model, $name)) {
      $class = get_class($this->model);
      throw new RuntimeException("$class::$name() 方法不存在");
    }
    // 修复#5: 转发调用时添加return，确保返回值不丢失
    return call_user_func_array([$this->model, $name], $arguments);
  }

  /**
   * 执行 CRUD 操作前注入模型业务逻辑（软删除过滤、时间戳写入等），查询后执行关联查询
   *
   * @param string $type 操作类型 insert|insertGetId|update|delete|select
   * @return Raw|string|array|int 查询结果
   */
  #[Override]
  protected function runCrud(string $type): Raw|string|array|int
  {
    $this->handleCrud($type);
    $result = parent::runCrud($type);
    if ($result instanceof Raw) return $result;
    if ($type === 'select' && !empty($this->relations)) {
      /** @noinspection PhpUnhandledExceptionInspection */
      $result = $this->queryRelationData($result);
    }
    return $result;
  }

  /**
   * 根据 CRUD 类型注入模型业务逻辑：软删除过滤、时间戳自动写入、主键自动生成
   *
   * @param string $type 操作类型
   */
  private function handleCrud(string $type): void
  {
    switch ($type) {
      case 'select':
        // 排除已被软删除的数据
        if ($this->enableSoftDelete && !$this->withTrashed) {
          if (is_null($this->softDeleteFieldDefaultValue)) {
            $this->whereNull($this->softDeleteFieldName);
          } else {
            $this->where($this->softDeleteFieldName, '=', $this->softDeleteFieldDefaultValue);
          }
        }
        break;
      case 'update':
        // 自动写入更新时间
        if (in_array($this->autoWriteTimestamp, [2, 3])) {
          if (!array_key_exists($this->updateTimeFieldName, $this->options->data)) {
            $writeTime = $this->_getTime($this->updateTimeFormatType);
            $this->options->data[$this->updateTimeFieldName] = $writeTime;
          }
        }
        break;
      case 'insertGetId':
      case 'insert':
        $isMoreWrite = Arr::isIndexArray($this->options->data);
        // 自动写入创建时间
        if (in_array($this->autoWriteTimestamp, [1, 3])) {
          if ($isMoreWrite) {
            array_walk($this->options->data, function (&$item) {
              if (!array_key_exists($this->createTimeFieldName, $item)) {
                $item[$this->createTimeFieldName] = $this->_getTime($this->createTimeFormatType);
              }
            });
          } elseif (!array_key_exists($this->createTimeFieldName, $this->options->data)) {
            $this->options->data[$this->createTimeFieldName] = $this->_getTime(
              $this->createTimeFormatType
            );
          }
        }
        // 自动写入主键
        if ($this->autoWritePk && property_exists($this->model, 'autoWritePk')) {
          if ($isMoreWrite) {
            array_walk($this->options->data, function (&$item) {
              if (!array_key_exists($this->pk, $item)) {
                $item[$this->pk] = $this->model->{'autoWritePk'}();
              }
            });
          } elseif (!array_key_exists($this->pk, $this->options->data)) {
            $this->options->data[$this->pk] = $this->model->{'autoWritePk'}();
          }
        }
        break;
    }
  }

  /**
   * 使用协程并发查询关联数据并填充到主数据中
   *
   * 并发安全设计：各协程把查询结果写入独立槽位（$maps[关联名]），
   * 互不覆盖；全部完成后由当前协程串行合并到主数据，
   * 避免整体赋值 $data 导致后完成协程覆盖先完成协程的关联数据。
   *
   * @param array $data 主表查询结果
   * @return array 填充关联数据后的结果
   * @throws Throwable 关联查询异常时抛出
   */
  protected function queryRelationData(array $data): array
  {
    if (empty($data)) return [];
    // 捕获到的异常
    $throw = null;
    // 各协程独立的结果槽位：关联名 => 外键映射
    $maps = [];
    $wg = new WaitGroup();
    foreach ($this->relations as $name => $relation) {
      if ($throw) break;
      $wg->add();
      // $data 按值捕获（协程内不修改主数据），结果仅写入自己的槽位
      Coroutine::create(function () use ($wg, $name, $relation, $data, &$maps, &$throw) {
        try {
          $maps[$name] = $relation->query($data, $name);
        } catch (Throwable $e) {
          // 捕获一切异常并记录
          $throw = $e;
        } finally {
          $wg->done();
        }
      });
    }
    //挂起当前协程，等待所有任务完成后恢复
    $wg->wait();
    // 如果捕获到了异常 则抛出异常
    if ($throw) throw $throw;
    // 主协程串行合并各关联数据，逐行填充关联字段
    foreach ($maps as $name => $keyMap) {
      $relation = $this->relations[$name];
      $localKey = $relation->localKey();
      $isMany = $relation->isMany();
      $relationQuery = $relation->relationModel()->query;
      array_walk($data, function (&$row) use ($localKey, $isMany, $relationQuery, $keyMap, $name) {
        $key = $row[$localKey] ?? null;
        if (array_key_exists($key, $keyMap)) {
          $row[$name] = $keyMap[$key];
        } else {
          // 未命中外键：一对多给空集合，一对一给空数据集
          $row[$name] = $isMany
            ? new \Viswoole\Database\Collection($relationQuery->newQuery(), [])
            : new DataSet($relationQuery->newQuery(), []);
        }
      });
    }
    return $data;
  }

  /**
   * 创建一条数据并返回 DataSet
   *
   * @param array $data 关联数组数据
   * @param array $columns 仅允许写入的列名，为空时不限制
   * @return DataSet 包含写入数据（含主键）的 DataSet
   * @throws DbException 数据库操作失败时抛出
   * @throws InvalidArgumentException 数据非关联数组时抛出
   */
  public function create(array $data, array $columns = []): DataSet
  {
    if (!Arr::isAssociativeArray($data)) {
      throw new InvalidArgumentException('Model::create() 输入数据必须是关联数组');
    }
    // 拿到只写入的列
    $filteredData = empty($columns) ? $data : array_intersect_key($data, array_flip($columns));
    // 写入数据并获取主键
    $data[$this->pk] = $this->insertGetId($filteredData);
    return new DataSet($this->newQuery(), $data);
  }

  /**
   * 创建全新的查询实例，基于当前模型的新实例
   *
   * @return static 新的查询实例
   */
  public function newQuery(): static
  {
    $class = get_class($this->model);
    $model = new $class();
    return $model->query;
  }
}
