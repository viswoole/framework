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

use Viswoole\Database\Raw;

/**
 * 查询执行信息
 *
 * 不可变值对象，记录单次查询的 SQL、缓存策略与耗时统计。
 */
readonly class RunInfo
{
  /**
   * @var Raw 已执行的 SQL 语句（含参数绑定）
   */
  public Raw $sql;
  /**
   * @var bool|array{tag:string,expire:int,expiry:int} 缓存策略，false 表示未启用缓存
   */
  public bool|array $cache;
  /**
   * @var array{start_time:float,end_time:float,cost_time_s:float,cost_time_ms:float} 执行耗时统计
   */
  public array $time;

  /**
   * @param Raw $sql 已执行的 SQL 语句
   * @param bool|array $cache 缓存策略，false 表示未启用
   * @param array $time 执行耗时统计，包含 start_time / end_time / cost_time_s / cost_time_ms
   */
  public function __construct(
    Raw        $sql,
    bool|array $cache,
    array      $time
  )
  {
    $this->sql = $sql;
    $this->cache = $cache;
    $this->time = $time;
  }
}
