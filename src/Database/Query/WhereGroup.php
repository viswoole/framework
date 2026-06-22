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
use Viswoole\Database\BaseQuery;
use Viswoole\Database\Raw;

/**
 * WHERE 条件分组
 *
 * 将多条查询条件封装为一个带括号的逻辑组，
 * 用于构建 (A AND B) OR (C AND D) 等嵌套条件。
 */
class WhereGroup
{
  /**
   * @var string 组间连接符：AND | OR
   */
  public string $connector;
  /**
   * 组内条件列表
   *
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}|Raw|WhereGroup>
   */
  public array $items;

  /**
   * @param array $wheres 原始条件数组，支持简写语法
   * @param string $connector 组间连接符，AND 或 OR（不区分大小写，其他值默认 OR）
   */
  public function __construct(array $wheres, string $connector)
  {

    $this->items = self::parsing($wheres);
    $connector = strtoupper($connector);
    $connector = $connector === 'AND' ? 'AND' : 'OR';
    $this->connector = $connector;
  }

  /**
   * 将原始条件数组解析为统一结构
   *
   * 支持两种简写：
   *  - 关联键值对：['name' => 'foo'] 等价于 name = foo
   *  - 索引数组：  ['name', '=', 'foo', 'AND']（第三项起可省略连接符）
   *
   * @param array $wheres 原始条件数组
   * @return array<int,array{column:string,operator:string,value:mixed,connector:string}> 标准化后的条件列表
   * @throws InvalidArgumentException 条件格式无效或运算符不支持时抛出
   */
  public static function parsing(array $wheres): array
  {
    $newWheres = [];
    foreach ($wheres as $key => $item) {
      if (is_string($key)) {
        $where = [
          'column' => $key,
          'operator' => is_array($item) ? 'IN' : '=',
          'value' => $item,
          'connector' => 'AND'
        ];
      } else {
        if (!is_array($item) || count($item) < 3) {
          throw new InvalidArgumentException("无效的查询条件 index：$key");
        }
        $operator = $item[1];
        if (!in_array($operator, BaseQuery::OPERATORS)) {
          throw new InvalidArgumentException("无效的查询条件运算符 index：$key");
        }
        $where = [
          'column' => $item[0],
          'operator' => $operator,
          'value' => $item[2],
          'connector' => strtoupper($item[3] ?? 'AND')
        ];
      }
      if (is_array($where['value']) && empty($where['value'])) {
        $operator = $where['operator'];
        throw new InvalidArgumentException("$operator 条件值不能是空数组");
      }
      $newWheres[] = $where;
    }
    return $newWheres;
  }
}
