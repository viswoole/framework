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

namespace Viswoole\Tests\Cache;

use Viswoole\Cache\Driver\Redis;

/**
 * 测试Redis缓存
 */
class RedisTest extends FileTest
{
  /**
   * @return void
   */
  protected function setUp(): void
  {
    // REDIS_HOST 由 docker-compose 设置为 redis（Compose 网络内解析）
    // 回退到 host.docker.internal 供 PhpStorm 等 IDE 直接创建容器时通过宿主机映射端口连接
    $host = getenv('REDIS_HOST') ?: 'host.docker.internal';
    $this->cache = new Redis($host);
    $this->cache->clear();
  }
}
