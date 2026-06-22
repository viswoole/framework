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

use ArrayAccess;
use Exception;
use Override;
use Viswoole\Database\Raw;

/**
 * 查询构建选项的数据容器
 *
 * 承载 SELECT / INSERT / UPDATE / DELETE 各类查询所需的全部选项，
 * 同时实现 ArrayAccess 以支持选项的数组式读写。
 */
class Options implements ArrayAccess
{
  /**
   * @var string 目标数据表名
   */
  public string $table;
  /**
   * @var string 主键字段名，用于自动推导 WHERE 条件
   */
  public string $pk;
  /**
   * @var string 表别名，为空则不使用别名
   */
  public string $alias = '';
  /**
   * @var false|array{key:string,store:string|null,tag:string|null,expire:int} 缓存配置，false 表示不缓存
   */
  public false|array $cache = false;
  /**
   * @var array<string,string|null> 查询列，键为列名、值为列别名；为空时等同于 SELECT *
   */
  public array $columns = [];
  /**
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}|Raw|WhereGroup> WHERE 条件列表
   */
  public array $where = [];
  /**
   * @var array<int,array{table:string,localKey:string,operator:string,foreignKey:string,type:string}> JOIN 子句列表
   */
  public array $join = [];
  /**
   * @var string[] GROUP BY 字段列表
   */
  public array $groupBy = [];
  /**
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}> HAVING 条件列表
   */
  public array $having = [];
  /**
   * @var array<int,array{column:string,direction:string}|Raw> ORDER BY 排序规则列表
   */
  public array $orderBy = [];
  /**
   * @var int|null 返回行数上限，null 表示不限制
   */
  public ?int $limit = null;
  /**
   * @var int|null 结果偏移行数，null 表示不偏移
   */
  public ?int $offset = null;
  /**
   * @var array<int,array{query:string,type:string}> UNION 合并查询列表
   */
  public array $unions = [];
  /**
   * @var bool 是否去重（SELECT DISTINCT）
   */
  public bool $distinct = false;
  /**
   * @var string|null 强制索引名称，null 表示不指定
   */
  public ?string $force = null;
  /**
   * @var string 查询操作类型：insert|insertGetId|update|delete|select
   */
  public string $type = '';

  /**
   * @var array 要写入的数据，单条为关联数组，批量为索引数组
   */
  public array $data = [];
  /**
   * @var bool 是否加排他锁（FOR UPDATE），阻止其他事务读写
   */
  public bool $lockForUpdate = false;
  /**
   * @var bool 是否加共享锁（LOCK IN SHARE MODE），允许其他事务读但不允许写
   */
  public bool $sharedLock = false;
  /**
   * @var bool 是否仅生成 SQL 而不实际执行
   */
  public bool $toRaw = false;
  /**
   * @var bool 是否使用 REPLACE INTO（仅 MySQL 有效）
   */
  public bool $replace = false;
  /**
   * @var string[] 需要从查询结果中排除的列名
   */
  public array $withoutColumns = [];

  /**
   * @var bool 是否严格校验写入字段是否存在于表结构中
   */
  public bool $strict = true;

  /**
   * @param string $table 目标数据表名
   * @param string $pk 主键字段名
   */
  public function __construct(string $table, string $pk)
  {
    $this->table = $table;
    $this->pk = $pk;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    if ($this->offsetExists($offset)) {
      return $this->{$offset};
    } else {
      trigger_error("Undefined property: $offset", E_USER_WARNING);
      return null;
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return property_exists($this, $offset);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    if (property_exists($this, $offset)) {
      $this->{$offset} = $value;
    }
  }

  /**
   * 禁止注销属性，调用将抛出异常
   *
   * @inheritDoc
   * @throws Exception 始终抛出，不允许 unset 操作
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    throw new Exception("Unset property $offset is not allowed");
  }
}
