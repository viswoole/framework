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
 * 不可变值对象，记录单次查询的 SQL、缓存策略、耗时统计与执行通道信息。
 */
readonly class RunInfo
{
  /**
   * @var Raw 已执行的 SQL 语句（含参数绑定）
   */
  public Raw $sql;
  /**
   * @var false|array{key:string,store:string|null,tag:string|null,expire:int} 缓存策略，false 表示未启用缓存
   */
  public false|array $cache;
  /**
   * @var array{start_time:float,end_time:float,cost_time_s:float,cost_time_ms:float} 执行耗时统计
   */
  public array $time;
  /**
   * @var string 执行查询的通道名称，空串表示未注册的裸通道
   */
  public string $channel;
  /**
   * @var string 读写路由类型：read|write（读写分离场景下区分主从库），
   *             空串表示通道未提供路由信息（非 PDO 通道）
   */
  public string $route;

  /**
   * @param Raw $sql 已执行的 SQL 语句
   * @param false|array $cache 缓存策略，false 表示未启用
   * @param array $time 执行耗时统计，包含 start_time / end_time / cost_time_s / cost_time_ms
   * @param string $channel 执行查询的通道名称
   * @param string $route 读写路由类型 read|write，无路由信息时为空串
   */
  public function __construct(
    Raw         $sql,
    false|array $cache,
    array       $time,
    string      $channel = '',
    string      $route = ''
  )
  {
    $this->sql = $sql;
    $this->cache = $cache;
    $this->time = $time;
    $this->channel = $channel;
    $this->route = $route;
  }
}
