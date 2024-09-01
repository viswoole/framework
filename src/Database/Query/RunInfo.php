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
 * 查询信息
 */
readonly class RunInfo
{
  /**
   * @var Raw 运行的SQL语句
   */
  public Raw $sql;
  /**
   * @var bool|array{tag:string,expire:int,expiry:int} 缓存信息
   */
  public bool|array $cache;
  /**
   * @var array{start_time:float,end_time:float,cost_time_s:float,cost_time_ms:float}} 执行时间信息
   */
  public array $time;

  /**
   * @param Raw $sql 运行的SQL语句
   * @param bool|array $cache 缓存信息
   * @param array $time 执行时间信息
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
