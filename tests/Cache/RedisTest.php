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

use PHPUnit\Framework\TestCase;
use Viswoole\Cache\Driver\Redis;
use function Co\run;

class RedisTest extends TestCase
{
  public function testSet()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->set('test', '123');
      static::assertEquals('123', $cache->get('test'));
    });
  }

  public function testInc()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->set('test', 1);
      static::assertEquals(2, $cache->inc('test'));
      static::assertEquals(1, $cache->dec('test'));
    });
  }

  public function testPull()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->set('test', 1);
      static::assertEquals(1, $cache->pull('test'));
      static::assertFalse($cache->has('test'));
    });
  }

  public function testClear()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->set('test', 1);
      $cache->clear();
      static::assertFalse($cache->has('test'));
    });
  }

  public function testDelete()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->delete('test');
      static::assertNull($cache->get('test'));
    });
  }

  public function testArray()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->clear();
      $cache->sAddArray('test', [1, 2, 3]);
      static::assertEquals([1, 2, 3], $cache->getArray('test'));
      $cache->sAddArray('test', [4, 5, 6]);
      static::assertEquals([1, 2, 3, 4, 5, 6], $cache->getArray('test'));
      $cache->sRemoveArray('test', [4, 5, 6]);
      static::assertEquals([1, 2, 3], $cache->getArray('test'));
    });
  }

  public function testTag()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->clear();
      $tagInstance = $cache->tag('testTag');
      $tagInstance->set('test', 123);
      static::assertEquals(['tag:testTag'], $cache->getTags());
      static::assertEquals(123, $cache->get('test'));
      $tagInstance->clear();
      static::assertNull($cache->get('test'));
    });
  }

  public function testLock()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $id = $cache->lock('test');
      static::assertTrue($cache->unlock($id));
      static::assertTrue(is_string($cache->lock('test')));
    });
  }

  public function testTll()
  {
    run(function () {
      $cache = new Redis('viswoole-redis-1');
      $cache->set('test', 1, 10);
      static::assertIsInt($cache->ttl('test'));
      $cache->set('test', 1, 0);
      static::assertEquals(-1, $cache->ttl('test'));
      static::assertFalse($cache->ttl('test2'));
    });
  }
}
