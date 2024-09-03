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

use InvalidArgumentException;
use Override;
use RuntimeException;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Common\Str;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Model;
use Viswoole\Database\Query;
use Viswoole\Database\Raw;

//* @property array $hidden 隐藏字段，不对外暴露
//* @property bool $enableSoftDelete 是否启用软删除
//* @property string $softDeleteFieldName 软删除字段
//* @property string $softDeleteFieldType 软删除字段类型 datetime|timestamp|date|int
//* @property null|string|int $softDeleteFieldDefaultValue 软删除默认记录值
//* @property int $autoWriteTimestamp 自动写入时间戳0关闭1写入创建时间，2写入更新时间，3写入创建和更新时间
//* @property string $createTimeFieldName 创建时间字段
//* @property string $createTimeFormatType 创建时间字段类型：datetime|timestamp|date
//* @property string $updateTimeFieldName 更新时间字段名称
//* @property string $updateTimeFormatType 更新时间字段类型：datetime|timestamp|date|日期格式表达式
//* @property string $suffix 自动去除类名后缀
//* @property string $table 完整表名
//* @property string $pk 表主键
//* @property string|null $channelName 数据库通道名称，为null则使用默认通道
//* @property bool $autoWritePk 自动写入主键

/**
 * 模型查询构造器
 */
class ModelQuery extends Query
{
  /**
   * @var bool 是否包含软删除的数据
   */
  private bool $withTrashed;

  /**
   * 实例化一个模型查询实例
   */
  public function __construct(protected Model $model)
  {
    parent::__construct(
      Db::channel($this->channelName),
      $this->table, $this->pk
    );
  }

  /**
   * 创建一条数据，并返回数据集
   *
   * @param array $data
   * @param array $columns 只允许写入的列
   * @return DataSet
   */
  public function create(array $data, array $columns = []): DataSet
  {
    if (!Arr::isAssociativeArray($data)) {
      throw new InvalidArgumentException('Model::create() 输入数据必须是关联数组');
    }
    // 拿到只写入的列
    $filteredData = empty($columns) ? $data : array_intersect_key($data, array_flip($columns));
    // 写入数据并获取主键
    $id = $this->insertGetId($filteredData);
    if (!$id) throw new DbException('Model::create() 创建数据失败');
    $data[$this->pk] = $id;
    return new DataSet($this->newQuery(), $data);
  }

  /**
   * 新建查询实例
   *
   * @access public
   * @return $this 返回一个全新的查询实例
   */
  protected function newQuery(): static
  {
    $class = get_class($this->model);
    $model = new $class();
    return $model->query;
  }

  /**
   * 获取模型中的属性
   *
   * @param string $name
   * @return mixed
   */
  public function __get(string $name)
  {
    return $this->model->__properties($name);
  }

  /**
   * 删除记录
   *
   * @param bool $real 是否为硬删除，仅开启软删除功能时有效
   * @return int|Raw
   */
  #[Override] public function delete(bool $real = false): int|Raw
  {
    if ($this->enableSoftDelete && !$real) {
      return parent::update([
        $this->softDeleteFieldName => $this->_getTime($this->softDeleteFieldType)
      ]);
    }
    return parent::delete();
  }

  /**
   * 获取时间
   *
   * @param string $format
   * @return string
   */
  private function _getTime(string $format): string
  {
    return match ($format) {
      'datetime' => date('Y-m-d H:i:s'),
      'timestamp', 'time' => time(),
      'date' => date('Y-m-d'),
      'int' => 1,
      default => date($format)
    };
  }

  /**
   * 恢复软删除的数据
   *
   * @access public
   * @param int|string|array|null $id 要恢复记录的主键值，如果为空则必须指定where条件
   * @return int|Raw 如果未启用软删除功能，返回0，否则返回更新记录数
   * @throws RuntimeException
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
   * 获取隐藏字段
   *
   * @return array
   */
  public function getHiddenColumn(): array
  {
    return $this->hidden;
  }

  /**
   * 查询结果中包含软删除的数据
   *
   * @access public
   * @param bool $withTrashed
   * @return $this
   */
  public function withTrashed(bool $withTrashed = true): static
  {
    $this->withTrashed = $withTrashed;
    return $this;
  }

  /**
   * 使用获取器
   *
   * @access public
   * @param string $key
   * @param mixed $value
   * @return mixed
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
   * @inheritDoc
   */
  public function reset(): static
  {
    $this->withTrashed = false;
    return parent::reset();
  }

  /**
   * 转发方法回模型层
   *
   * @param string $name
   * @param array $arguments
   * @return void
   */
  public function __call(string $name, array $arguments)
  {
    if (!method_exists($this->model, $name)) {
      $class = get_class($this->model);
      throw new RuntimeException("$class::$name() 方法不存在");
    }
    call_user_func_array([$this->model, $name], $arguments);
  }

  /**
   * 运行crud方法
   *
   * @param string $type
   * @return Raw|string|array|int
   */
  protected function runCrud(string $type): Raw|string|array|int
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
    return parent::runCrud($type);
  }
}
