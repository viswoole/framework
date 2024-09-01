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

use Override;
use RuntimeException;
use Viswoole\Core\Common\Arr;

/**
 * 模型基类
 */
abstract class Model extends Query
{
  /** @var bool 是否启用软删除 */
  protected bool $enableSoftDelete = false;
  /** @var string 软删除字段 */
  protected string $softDeleteFieldName = 'delete_time';
  /** @var string 软删除字段类型 datetime|timestamp|date|int则删除为1 */
  protected string $softDeleteFieldType = 'datetime';
  /** @var string|int|null 软删除默认记录值 */
  protected null|string|int $softDeleteFieldDefaultValue = null;
  /** @var int 自动写入时间戳0关闭1写入创建时间，2写入更新时间，3写入创建和更新时间 */
  protected int $autoWriteTimestamp = 0;
  /** @var string 创建时间字段 */
  protected string $createTimeFieldName = 'create_time';
  /** @var string 创建时间字段类型：datetime|timestamp|date */
  protected string $createTimeFormatType = 'datetime';
  /** @var string 更新时间字段名称 */
  protected string $updateTimeFieldName = 'update_time';
  /** @var string 更新时间字段类型：datetime|timestamp|date|日期格式表达式 */
  protected string $updateTimeFormatType = 'datetime';
  /**
   * @var string 自动去除类名后缀
   */
  protected string $suffix = 'Model';
  /** @var string 完整表名 */
  protected string $table;
  /** @var string 表主键 */
  protected string $pk = 'id';
  /** @var string|null 数据库通道名称，为null则使用默认通道 */
  protected ?string $channel_name = null;
  /*** @var bool 查询是否包含软删除数据 */
  private bool $withTrashed = false;

  public function __construct()
  {
    if (!isset($this->table)) {
      // 获取类名，不包含命名空间
      $className = substr(strrchr(get_called_class(), "\\"), 1);
      // 去除类名中的尾部的 "Model" 字符
      $className = preg_replace('/' . preg_quote($this->suffix, '/') . '$/', '', $className);
      // 转换为蛇形命名
      $className = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));
      $this->table = $className;
    }
    parent::__construct(
      \Viswoole\Database\Facade\Db::channel($this->channel_name),
      $this->table, $this->pk
    );
  }

  /**
   * 静态调用入口
   *
   * @return static
   */
  public static function db(): static
  {
    return new static();
  }

  /**
   * 删除记录
   *
   * @param bool $real 是否为硬删除，仅开启软删除功能时有效
   * @return int|Raw
   */
  #[Override] public function delete(bool $real = false): int|Raw
  {
    if ($this->enableSoftDelete) {
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
   * 运行crud方法
   *
   * @param string $type
   * @return mixed
   */
  protected function runCrud(string $type): mixed
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
        // 自动写入创建时间
        if (in_array($this->autoWriteTimestamp, [1, 3])) {
          if (Arr::isIndexArray($this->options->data)) {
            array_walk($this->options->data, function (&$item) {
              if (array_key_exists($this->createTimeFieldName, $item)) {
                $item[$this->createTimeFieldName] = $this->_getTime($this->createTimeFormatType);
              }
            });
          } elseif (array_key_exists($this->createTimeFieldName, $this->options->data)) {
            $this->options->data[$this->createTimeFieldName] = $this->_getTime(
              $this->createTimeFormatType
            );
          }
        }
        break;
    }
    $result = parent::runCrud($type);
    // 查询完毕重置软删除
    $this->withTrashed = false;
    return $result;
  }
}
