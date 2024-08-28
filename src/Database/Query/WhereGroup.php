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
use Viswoole\Database\Query;
use Viswoole\Database\Raw;

/**
 * 查询条件组
 */
class WhereGroup
{
  /**
   * @var string 连接运算符
   */
  public string $connector;
  /**
   * 查询条件
   *
   * @var array<int,array{column:string,operator:string,value:mixed,connector:string}|Raw|WhereGroup>
   */
  public array $items;

  /**
   * @param array $wheres
   * @param string $connector
   */
  public function __construct(array $wheres, string $connector)
  {

    $this->items = self::parsing($wheres);
    $connector = strtoupper($connector);
    $connector = $connector === 'AND' ? 'AND' : 'OR';
    $this->connector = $connector;
  }

  /**
   * 解析数组
   *
   * @param array $wheres
   * @return array<int,array{column:string,operator:string,value:mixed,connector:string}>
   */
  public static function parsing(array $wheres): array
  {
    $newWheres = [];
    foreach ($wheres as $key => $item) {
      if (is_string($key)) {
        $newWheres[] = [
          'column' => $key,
          'operator' => is_array($item) ? 'IN' : '=',
          'value' => $item,
          'connector' => 'AND'
        ];
      } else if (is_int($key)) {
        if (!is_array($item) || count($item) < 3) {
          throw new InvalidArgumentException("无效的查询条件 index：$key");
        }
        $operator = $item[1];
        if (!in_array($operator, Query::OPERATORS)) {
          throw new InvalidArgumentException("无效的查询条件运算符 index：$key");
        }
        $newWheres[] = [
          'column' => $item[0],
          'operator' => $operator,
          'value' => $item[2],
          'connector' => strtoupper($item[3] ?? 'AND')
        ];
      } else {
        $newWheres[] = $item;
      }
    }
    return $newWheres;
  }
}
