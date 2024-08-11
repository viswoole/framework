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

use InvalidArgumentException;
use Viswoole\Database\Collector\Where\WhereGroup;
use Viswoole\Database\Collector\Where\WhereRaw;

/**
 * 查询选项
 * @noinspection PhpClassHasTooManyDeclaredMembersInspection
 */
class QueryOptions
{
  /**运算符*/
  public const array OPERATOR = [
    '=',
    '!=',
    '<>',
    '>',
    '>=',
    '<',
    '<=',
    'LIKE',
    'BETWEEN',
    'NOT BETWEEN',
    'IN',
    'NOT IN',
    '= DATE',
    '!= DATE',
    '<> DATE',
    '> DATE',
    '>= DATE',
    '< DATE',
    '<= DATE',
    'BETWEEN DATE',
    'NOT BETWEEN DATE',
    'IN DATE',
    'NOT IN DATE',
    '= TIME',
    '!= TIME',
    '<> TIME',
    '> TIME',
    '>= TIME',
    '< TIME',
    '<= TIME',
    'BETWEEN TIME',
    'NOT BETWEEN TIME',
    'IN TIME',
    'NOT IN TIME',
  ];
  /**
   * @var CrudMethod 查询的方法
   */
  public CrudMethod $crudMethod;
  /**
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}|WhereGroup|WhereRaw> 查询条件
   */
  public array $where = [];
  /**
   * @var array{string,mixed}|array<int,array{string,mixed}> 增改数据
   */
  public array $data = [];
  /**
   * @var string|null 表别名
   */
  public ?string $alias = null;
  /**
   * @var array<string,string|null>|string 查询字段，关联数组[字段名=>别名|null]
   */
  public array|string|Raw $field = '*';
  /**
   * @var string[] 排除的字段
   */
  public array $withoutField = [];
  /**
   * @var int 限制查询数量
   */
  public int $limit = 0;
  /**
   * @var int 偏移量
   */
  public int $offset = 0;
  /**
   * @var string 备注
   */
  public string $comment = '';
  /**
   * @var string|null 过滤条件
   */
  public ?string $having = null;
  /**
   * @var array{column:array,direction:string} 排序
   */
  public array $order = [];
  /**
   * @var string 聚合查询的列
   */
  public string $columnName = '*';
  /**
   * @var false|array{key:string,store:string|null,tag:string|null,expiry:int} 是否读取缓存
   */
  public false|array $cache = false;
  /**
   * @var array<string,int> 自动递减
   */
  public array $autoDec = [];
  /**
   * @var array<string,int> 自动递增
   */
  public array $autoInc = [];
  /**
   * @var array<int,array{table:string,alias:string,condition:string,type:string}> 连接查询
   */
  public array $join = [];
  /**
   * @var string[] 分组
   */
  public array $group = [];
  /**
   * @var string|null 强制索引
   */
  public ?string $force = null;
  /**
   * @var bool 筛选不重复的值
   */
  public bool $distinct = false;
  /**
   * @var bool 是否强制写入mysql可用
   */
  public bool $replace = false;
  /**
   * @var bool 是否允许为空
   */
  public bool $allowEmpty = true;
  /**
   * @var bool 插入数据是否获取主键值
   */
  public bool $insertGetId = false;
  /**
   * @var string[] union
   */
  public array $union = [];
  /**
   * @var string[] unionAll
   */
  public array $unionAll = [];
  /**
   * @var string[] mysql分区查询
   */
  public array $partition = [];

  /**
   * @param string $table 完整表名
   */
  public function __construct(public string $table, public string $pk)
  {
  }

  /**
   * 添加where条件
   *
   * @param string|array $column
   * @param string $operator
   * @param mixed $value
   * @param string $connector
   * @return void
   */
  public function addWhere(
    string|array $column,
    string       $operator,
    mixed        $value,
    string       $connector
  ): void
  {
    $operator = strtoupper($operator);
    if (in_array($operator, self::OPERATOR)) {
      throw new InvalidArgumentException(
        'where condition operator must be in ' . implode(',', self::OPERATOR)
      );
    }
    $connector = strtoupper($connector) === 'AND' ? 'AND' : 'OR';
    $data = [
      'column' => $column,
      'operator' => strtoupper($operator),
      'value' => $value,
      'connector' => $connector
    ];
    $this->where[] = $data;
  }
}
