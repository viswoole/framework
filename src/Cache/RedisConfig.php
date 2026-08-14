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

namespace Viswoole\Cache;


/**
 * Redis 连接与连接池的不可变配置对象
 *
 * 封装 Redis 服务器连接参数、缓存驱动通用参数及连接池容量配置，
 * 以 readonly 类形式确保配置在创建后不可修改。
 */
readonly class RedisConfig
{

  /**
   * 初始化 Redis 配置
   *
   * @param string $host Redis 服务器地址
   * @param int $port Redis 服务器端口
   * @param string $password 认证密码，空字符串表示无密码
   * @param int $db_index Redis 数据库索引（0-15）
   * @param float $timeout 连接超时时间（秒）
   * @param int $retry_interval 重连等待间隔（毫秒）
   * @param float $read_timeout 读取超时时间（秒）
   * @param string $prefix 缓存键前缀
   * @param string $tag_prefix 标签键前缀标识
   * @param int $expire 默认过期时间（秒），0 表示永不过期
   * @param string $tag_store 标签仓库键名，不能为空
   * @param int $pool_max_size 连接池最大连接数
   * @param int $pool_fill_size 连接池最小填充连接数，0 表示不预填充
   */
  public function __construct(
    public string $host = '127.0.0.1',
    public int    $port = 6379,
    public string $password = '',
    public int    $db_index = 0,
    public float  $timeout = 0,
    public int    $retry_interval = 1000,
    public float  $read_timeout = 0,
    public string $prefix = '',
    public string $tag_prefix = 'tag:',
    public int    $expire = 0,
    public string $tag_store = 'TAG_STORE',
    public int    $pool_max_size = 10,
    public int    $pool_fill_size = 0
  )
  {
  }
}
