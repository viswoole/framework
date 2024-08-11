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

namespace Viswoole\Database\Collector;

use Viswoole\Database\Collector\Trait\ComputeTrait;
use Viswoole\Database\Collector\Trait\CrudTrait;
use Viswoole\Database\Collector\Trait\GroupTrait;
use Viswoole\Database\Collector\Trait\JoinTrait;
use Viswoole\Database\Collector\Trait\MysqlTrait;
use Viswoole\Database\Collector\Trait\PageTrait;
use Viswoole\Database\Collector\Trait\UnionTrait;
use Viswoole\Database\Collector\Trait\WhereTrait;
use Viswoole\Database\Driver\Contract\ChannelInterface;

/**
 * 查询构造器
 */
class QueryCollector
{
  use WhereTrait, JoinTrait, CrudTrait, ComputeTrait, PageTrait, UnionTrait, MysqlTrait,
    GroupTrait;

  /**
   * @var QueryOptions 当前查询参数
   */
  private QueryOptions $options;

  /**
   * @param ChannelInterface $driver 数据库驱动
   * @param string $table 要查询的表名
   * @param string $pk 主键
   */
  public function __construct(
    protected ChannelInterface $driver,
    protected string           $table,
    protected string           $pk
  )
  {
  }

  /**
   * 要查询的字段
   *
   * @access public
   * @param array|string|Raw $fields 要查询的字段
   * @return static
   */
  public function field(array|string|Raw $fields = '*'): static
  {
    if ($fields !== '*') $fields = self::formatFields($fields);
    $this->options->field = $fields;
    return $this;
  }

  /**
   * 把选择的字段转换为数组
   *
   * @param array|string $fields
   * @return array|string
   */
  protected static function formatFields(array|string $fields): string|array
  {
    $parsedFields = [];
    if (is_string($fields)) {
      $fields = explode(',', trim($fields));
      foreach ($fields as $item) {
        $fieldParts = explode(' as ', trim($item));
        if (count($fieldParts) === 2) $alias = trim($fieldParts[1]);
        $parsedFields[trim($fieldParts[0])] = $alias ?? null;
      }
    } else {
      foreach ($fields as $key => $value) {
        if (is_int($key)) {
          $parsedFields[$value] = null;
        } else {
          $parsedFields[$key] = $value;
        }
      }
    }
    return $parsedFields;
  }

  /**
   * 要排除的字段
   *
   * @access public
   * @param string|string[] $fields 要排除的字段
   * @return static
   */
  public function withoutField(array|string $fields): static
  {
    $this->options->withoutField = is_string($fields) ? explode(',', $fields) : $fields;
    return $this;
  }

  /**
   * 设置别名
   *
   * @access public
   * @param string $alias
   * @return static
   */
  public function alias(string $alias): static
  {
    $this->options->alias = $alias;
    return $this;
  }

  /**
   * 排序
   *
   * @access public
   * @param string $column
   * @param string $direction
   * @return static
   */
  public function order(string $column, string $direction = 'ASC'): static
  {
    $direction = strtoupper($direction);
    $direction = $direction === 'ASC' ? 'ASC' : 'DESC';
    $this->options->order[$column] = $direction;
    return $this;
  }

  /**
   * 筛选不重复的值
   *
   * @access public
   * @param bool $distinct
   * @return static
   */
  public function distinct(bool $distinct = true): static
  {
    $this->options->distinct = $distinct;
    return $this;
  }

  /**
   * 强制索引
   *
   * @access public
   * @param string $index
   * @return static
   */
  public function force(string $index): static
  {
    $this->options->force = $index;
    return $this;
  }

  /**
   * 添加注释
   *
   * @access public
   * @param string $comment
   * @return static
   */
  public function comment(string $comment): static
  {
    $this->options->comment = $comment;
    return $this;
  }

  /**
   * 查询数据为空时是否抛出错误
   *
   * @access public
   * @param bool $allow 设置为false则未查询到数据时会抛出异常
   * @return static
   */
  public function allowEmpty(bool $allow = false): static
  {
    $this->options->allowEmpty = $allow;
    return $this;
  }

  /**
   * 缓存
   * 如果在写入数据时使用缓存，则会自动清除读取缓存
   *
   * @param string $key 缓存标识
   * @param string|null $store 缓存通道
   * @param string|null $tag 缓存标签
   * @param int $expiry 缓存有效时间 默认0永久
   * @return static
   */
  public function cache(
    string $key,
    string $store = null,
    string $tag = null,
    int    $expiry = 0
  ): static
  {
    $this->options->cache = compact('key', 'store', 'tag', 'expiry');
    return $this;
  }

  /**
   * 构建sql
   *
   * @param CrudMethod $crud crud方法
   * @param bool $merge 是否将参数和sql语句合并，不使用占位符
   * @return string|array{sql:string,params:array<string,mixed>}
   */
  public function build(CrudMethod $crud = CrudMethod::SELECT, bool $merge = true): string|array
  {
    $this->options->crudMethod = $crud;
    $sql = $this->driver->build($this->options, $merge);
    $this->options = new QueryOptions($this->table, $this->pk);
    return $sql;
  }

  /**
   * 设置写入/更新数据
   *
   * @param array $data 要更新/写入的数据
   * @param bool $merge 是否合并已有数据
   * @return static
   */
  public function data(array $data, bool $merge = false): static
  {
    $this->options->data = $merge ? array_merge($this->options->data, $data) : $data;
    return $this;
  }
}
