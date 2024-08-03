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

namespace Viswoole\Database\Collector\Where;

use Viswoole\Database\Collector\Raw;

class WhereGroup
{
  /**
   * @var string 连接运算符
   */
  public string $connector;
  /**
   * 查询条件
   *
   * @var array<int,array{column:string|Raw,operator:string,value:mixed|Raw,connector:string}>
   */
  public array $items;

  public function __construct(array $wheres, string $connector)
  {
    $newWheres = [];
    foreach ($wheres as $key => $item) {
      if (is_string($key)) {
        $newWheres[] = [
          'column' => $key,
          'operator' => '=',
          'value' => $item,
          'connector' => 'AND'
        ];
      } else {
        $newWheres[] = [
          'column' => $item[0],
          'operator' => $item[1],
          'value' => $item[2],
          'connector' => strtoupper($item[3] ?? 'AND')
        ];
      }
    }
    $this->items = $newWheres;
    $connector = strtoupper($connector);
    $connector = $connector === 'AND' ? 'AND' : 'OR';
    $this->connector = $connector;
  }
}
