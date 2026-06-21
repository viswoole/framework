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

namespace Viswoole\Tests\Core;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Event;

/**
 * 事件系统测试
 */
class EventTest extends TestCase
{
  /**
   * @var Event 事件管理器实例
   */
  private Event $event;

  /**
   * 测试前初始化 Event 实例并清除所有监听
   *
   * @return void
   */
  protected function setUp(): void
  {
    $this->event = App::factory()->make(Event::class);
    $this->event->offAll();
  }

  /**
   * 测试注册和触发事件 - 使用闭包
   *
   * @return void
   */
  public function testOnAndEmitWithClosure(): void
  {
    $received = null;
    $this->event->on('testEvent', function ($data) use (&$received) {
      $received = $data;
    });
    $this->event->emit('testEvent', ['hello']);

    static::assertEquals('hello', $received);
  }

  /**
   * 测试事件名称不区分大小写
   *
   * @return void
   */
  public function testEventNameCaseInsensitive(): void
  {
    $received = false;
    $this->event->on('TestEvent', function () use (&$received) {
      $received = true;
    });
    // 使用小写触发
    $this->event->emit('testevent');

    static::assertTrue($received);
  }

  /**
   * 测试监听次数限制 - 达到限制后自动移除
   *
   * @return void
   */
  public function testListenerLimit(): void
  {
    $count = 0;
    $this->event->on('limitedEvent', function () use (&$count) {
      $count++;
    }, 2);

    $this->event->emit('limitedEvent');
    $this->event->emit('limitedEvent');
    $this->event->emit('limitedEvent'); // 第三次不应执行

    static::assertEquals(2, $count);
  }

  /**
   * 测试关闭特定监听器
   *
   * @return void
   */
  public function testOffSpecificListener(): void
  {
    $received = false;
    $id = $this->event->on('offTest', function () use (&$received) {
      $received = true;
    });

    $this->event->off('offTest', $id);
    $this->event->emit('offTest');

    static::assertFalse($received);
  }

  /**
   * 测试关闭事件的所有监听器
   *
   * @return void
   */
  public function testOffAllListenersForEvent(): void
  {
    $count = 0;
    $this->event->on('multiListener', function () use (&$count) {
      $count++;
    });
    $this->event->on('multiListener', function () use (&$count) {
      $count++;
    });

    $this->event->off('multiListener');
    $this->event->emit('multiListener');

    static::assertEquals(0, $count);
  }

  /**
   * 测试清除所有事件监听
   *
   * @return void
   */
  public function testOffAll(): void
  {
    $received = false;
    $this->event->on('event1', function () use (&$received) {
      $received = true;
    });
    $this->event->on('event2', function () use (&$received) {
      $received = true;
    });

    $this->event->offAll();
    $this->event->emit('event1');
    $this->event->emit('event2');

    static::assertFalse($received);
    static::assertEmpty($this->event->getEvents());
  }

  /**
   * 测试获取已监听的事件
   *
   * @return void
   */
  public function testGetEvents(): void
  {
    $this->event->on('eventA', fn() => null);
    $this->event->on('eventB', fn() => null);

    $events = $this->event->getEvents();
    static::assertContains('eventa', $events);
    static::assertContains('eventb', $events);
    static::assertCount(2, $events);
  }

  /**
   * 测试事件名称不能包含点号
   *
   * @return void
   */
  public function testEventNameCannotContainDot(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->event->on('invalid.event', fn() => null);
  }

  /**
   * 测试触发不存在的事件 - 不应报错
   *
   * @return void
   */
  public function testEmitNonExistentEvent(): void
  {
    // 触发不存在的事件不应抛出异常
    $this->event->emit('nonExistentEvent');
    static::assertTrue(true);
  }

  /**
   * 测试多个监听器按注册顺序执行
   *
   * @return void
   */
  public function testMultipleListenersExecutionOrder(): void
  {
    $order = [];
    $this->event->on('orderTest', function () use (&$order) {
      $order[] = 'first';
    });
    $this->event->on('orderTest', function () use (&$order) {
      $order[] = 'second';
    });
    $this->event->on('orderTest', function () use (&$order) {
      $order[] = 'third';
    });

    $this->event->emit('orderTest');

    static::assertEquals(['first', 'second', 'third'], $order);
  }

  /**
   * 测试触发事件时传递多个参数
   *
   * @return void
   */
  public function testEmitWithMultipleArguments(): void
  {
    $receivedArgs = [];
    $this->event->on('multiArgs', function ($arg1, $arg2, $arg3) use (&$receivedArgs) {
      $receivedArgs = [$arg1, $arg2, $arg3];
    });

    $this->event->emit('multiArgs', ['value1', 'value2', 'value3']);

    static::assertEquals(['value1', 'value2', 'value3'], $receivedArgs);
  }
}
