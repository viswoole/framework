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
use Viswoole\Cache\Driver\File;

class FileTest extends TestCase
{
  protected File $cache;

  public function testSet()
  {
    $this->cache->set('test', '123');
    static::assertEquals('123', $this->cache->get('test'));
  }

  public function testInc()
  {
    $this->cache->set('test', 1);
    static::assertEquals(2, $this->cache->inc('test'));
    static::assertEquals(1, $this->cache->dec('test'));
  }

  public function testPull()
  {
    $this->cache->set('test', 1);
    static::assertEquals(1, $this->cache->pull('test'));
    static::assertFalse($this->cache->has('test'));
  }

  public function testClear()
  {
    $this->cache->set('test', 1);
    $this->cache->clear();
    static::assertFalse($this->cache->has('test'));
  }

  public function testDelete()
  {
    $this->cache->delete('test');
    static::assertNull($this->cache->get('test'));
  }

  public function testArray()
  {
    $this->cache->sAddArray('test', [1, 2, 3]);
    static::assertEquals([1, 2, 3], $this->cache->get('test'));
    $this->cache->sAddArray('test', [4, 5, 6]);
    static::assertEquals([1, 2, 3, 4, 5, 6], $this->cache->getArray('test'));
    $this->cache->sRemoveArray('test', [4, 5, 6]);
    static::assertEquals([1, 2, 3], $this->cache->getArray('test'));
  }

  public function testTag()
  {
    $tagInstance = $this->cache->tag('testTag');
    $tagInstance->set('test', 123);
    static::assertEquals(['tag:testTag'], $this->cache->getTags());
    static::assertEquals(123, $this->cache->get('test'));
    $tagInstance->clear();
    static::assertNull($this->cache->get('test'));
  }

  public function testLock()
  {
    $id = $this->cache->lock('test');
    static::assertTrue($this->cache->unlock($id));
    static::assertTrue(is_string($this->cache->lock('test')));
  }

  public function testTll()
  {
    $this->cache->set('test', 1, 10);
    static::assertIsInt($this->cache->ttl('test'));
    $this->cache->set('test', 1, 0);
    static::assertEquals(-1, $this->cache->ttl('test'));
    static::assertFalse($this->cache->ttl('test2'));
  }

  protected function setUp(): void
  {
    parent::setUp();
    // 配置全局的根路径
    !defined('BASE_PATH') && define('BASE_PATH', dirname(realpath(__DIR__), 3));
    $this->cache = new File();
    $this->cache->clear();
  }
}
