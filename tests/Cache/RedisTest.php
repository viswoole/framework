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
    $host = getenv('REDIS_HOST') ?: 'redis';
    $this->cache = new Redis($host);
    $this->cache->clear();
  }
}
