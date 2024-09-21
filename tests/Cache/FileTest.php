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

/**
 * 文件缓存测试
 */
class FileTest extends TestCase
{
  protected File $cache;

  /**
   * 测试写入缓存数据
   *
   * @return void
   */
  public function testSet()
  {
    $res = $this->cache->set('test', '123');
    static::assertTrue($res);
    static::assertEquals('123', $this->cache->get('test'));
  }

  /**
   * 测试自增和自减
   *
   * @return void
   */
  public function testIncAndDec()
  {
    $this->cache->set('test', 1);
    static::assertEquals(2, $this->cache->inc('test'));
    static::assertEquals(1, $this->cache->dec('test'));
  }

  /**
   * 测试获取并删除缓存数据
   *
   * @return void
   */
  public function testPull()
  {
    $this->cache->set('test', 1);
    static::assertEquals(1, $this->cache->pull('test'));
    static::assertFalse($this->cache->has('test'));
  }

  /**
   * 测试清除缓存
   *
   * @return void
   */
  public function testClear()
  {
    $this->cache->set('test', 1);
    $this->cache->clear();
    static::assertFalse($this->cache->has('test'));
  }

  /**
   * 测试删除缓存
   *
   * @return void
   */
  public function testDelete()
  {
    $this->cache->delete('test');
    static::assertNull($this->cache->get('test'));
  }

  /**
   * 测试数组缓存
   *
   * @return void
   */
  public function testArray()
  {
    $this->cache->sAddArray('test', [1, 2, 3]);
    static::assertEquals([1, 2, 3], $this->cache->get('test'));
    $this->cache->sAddArray('test', [4, 5, 6]);
    static::assertEquals([1, 2, 3, 4, 5, 6], $this->cache->getArray('test'));
    $this->cache->sRemoveArray('test', [4, 5, 6]);
    static::assertEquals([1, 2, 3], $this->cache->getArray('test'));
  }

  /**
   * 测试标签缓存
   *
   * @return void
   */
  public function testTag()
  {
    $tagInstance = $this->cache->tag('testTag');
    $tagInstance->set('test', 123);
    static::assertEquals(['tag:testTag'], $this->cache->getTags());
    static::assertEquals(123, $this->cache->get('test'));
    $tagInstance->clear();
    static::assertNull($this->cache->get('test'));
  }

  /**
   * 测试锁
   *
   * @return void
   */
  public function testLock()
  {
    $id = $this->cache->lock('test');
    static::assertTrue($this->cache->unlock($id));
    static::assertTrue(is_string($this->cache->lock('test')));
  }

  /**
   * 测试获取过期时间
   *
   * @return void
   */
  public function testTll()
  {
    $this->cache->set('test', 1, 10);
    static::assertIsInt($this->cache->ttl('test'));
    $this->cache->set('test', 1, 0);
    static::assertEquals(-1, $this->cache->ttl('test'));
    static::assertFalse($this->cache->ttl('test2'));
  }

  /**
   * @inheritDoc
   */
  protected function setUp(): void
  {
    parent::setUp();
    // 配置全局的根路径
    define('BASE_PATH', dirname(realpath(__DIR__), 3));
    $this->cache = new File();
    $this->cache->clear();
  }
}
