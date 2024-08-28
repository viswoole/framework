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
 * 构建查询选项
 */
class Options implements ArrayAccess
{
  /**
   * @var string 要查询的表名
   */
  public string $table;
  /**
   * @var string 主键
   */
  public string $pk;
  /**
   * @var string 表别名
   */
  public string $alias = '';
  /**
   * @var false|array{key:string,store:string|null,tag:string|null,expiry:int} 是否读取缓存
   */
  public false|array $cache = false;
  /**
   * @var array<string,string|null> 查询的列，如果为空则查询所有列, 键为列名，值为列别名
   */
  public array $columns = [];
  /**
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}|Raw|WhereGroup> 查询条件
   */
  public array $where = [];
  /**
   * @var array<int,array{table:string,localKey:string,operator:string,foreignKey:string,type:string}> 连接表
   */
  public array $join = [];
  /**
   * @var string[] 分组
   */
  public array $groupBy = [];
  /**
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}> having条件
   */
  public array $having = [];
  /**
   * @var array<string,string> 排序
   */
  public array $orderBy = [];
  /**
   * @var int|null 限制数量, null为不限制
   */
  public ?int $limit = null;
  /**
   * @var int|null 偏移量, null为不偏移
   */
  public ?int $offset = null;
  /**
   * @var array<int,array{query:string,type:string}> 合并查询
   */
  public array $unions = [];
  /**
   * @var bool 是否去重
   */
  public bool $distinct = false;
  /**
   * @var string|null 强制索引，null为不指定
   */
  public ?string $force = null;
  /**
   * @var string 查询类型`insert`|`update`|`delete`|`select`
   */
  public string $type = '';

  /**
   * @var array|null 要写入的数据
   */
  public ?array $data = null;
  /**
   * @var bool 排他锁，其他事务不能读取或修改该记录
   */
  public bool $lockForUpdate = false;
  /**
   * @var bool 共享锁，其他事务可以读取该记录，但无法修改
   */
  public bool $sharedLock = false;
  /**
   * @var bool 返回SQL语句，不执行查询
   */
  public bool $getSql = false;
  /**
   * @var bool 强制写入，仅mysql有效
   */
  public bool $replace = false;
  /**
   * @var string[] 要排除的列
   */
  public array $withoutColumns = [];

  /**
   * @param string $table 表名
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
   * @inheritDoc
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    throw new Exception("Unset property $offset is not allowed");
  }
}
