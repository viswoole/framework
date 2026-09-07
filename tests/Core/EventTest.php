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
use Viswoole\Core\FrameworkEvent;

/**
 * 测试用字符串枚举，验证事件名取枚举值
 */
enum TestStringEvent: string
{
  case UserLogin = 'user_login_event';
}

/**
 * 测试用数值枚举，验证事件名取枚举名
 */
enum TestIntEvent: int
{
  case UserLogin = 1;
}

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

  /**
   * 测试使用 FrameworkEvent 枚举注册和触发事件
   *
   * @return void
   */
  public function testOnAndEmitWithFrameworkEventEnum(): void
  {
    $received = false;
    $this->event->on(FrameworkEvent::ServerStarted, function () use (&$received) {
      $received = true;
    });
    // 使用枚举触发
    $this->event->emit(FrameworkEvent::ServerStarted);

    static::assertTrue($received);
  }

  /**
   * 测试枚举与等价小写字符串指向同一监听组
   *
   * @return void
   */
  public function testEnumAndStringNormalizeToSameListener(): void
  {
    $received = false;
    // 枚举注册
    $this->event->on(FrameworkEvent::AppInitialized, function () use (&$received) {
      $received = true;
    });
    // 等价的小写字符串触发，应命中同一监听器
    $this->event->emit('appinitialized');

    static::assertTrue($received);
  }

  /**
   * 测试使用 FrameworkEvent 枚举关闭监听器
   *
   * @return void
   */
  public function testOffWithFrameworkEventEnum(): void
  {
    $received = false;
    $id = $this->event->on(FrameworkEvent::RouterInitializing, function () use (&$received) {
      $received = true;
    });

    $this->event->off(FrameworkEvent::RouterInitializing, $id);
    $this->event->emit(FrameworkEvent::RouterInitializing);

    static::assertFalse($received);
  }

  /**
   * 测试自定义字符串枚举 - 事件名取枚举值
   *
   * @return void
   */
  public function testStringBackedEnumUsesEnumValue(): void
  {
    $received = false;
    $this->event->on(TestStringEvent::UserLogin, function () use (&$received) {
      $received = true;
    });
    // 字符串枚举以枚举值 'user_login_event' 作为事件名，字符串触发应命中同一监听组
    $this->event->emit('user_login_event');

    static::assertTrue($received);
  }

  /**
   * 测试自定义数值枚举 - 事件名取枚举名
   *
   * @return void
   */
  public function testIntBackedEnumUsesEnumName(): void
  {
    $received = false;
    $this->event->on(TestIntEvent::UserLogin, function () use (&$received) {
      $received = true;
    });
    // 数值枚举以枚举名作为事件名（归一化为小写 userlogin）
    $this->event->emit('userlogin');

    static::assertTrue($received);
  }

  /**
   * 测试监听器在触发过程中移除自身 - 不应产生警告或数据污染
   *
   * @return void
   */
  public function testListenerCanRemoveSelfDuringEmit(): void
  {
    $warnings = [];
    // 捕获 PHP 警告，验证 off 自身后 callHandle 不再访问已移除的键
    set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
      $warnings[] = $errstr;
      return true;
    });
    $calls = 0;
    $selfId = null;
    $selfId = $this->event->on('selfRemove', function () use (&$calls, &$selfId) {
      $calls++;
      $this->event->off('selfRemove', $selfId);
    });
    try {
      $this->event->emit('selfRemove');
      // 第二次触发时被污染的监听器结构不应存在，也不应产生警告
      $this->event->emit('selfRemove');
    } finally {
      restore_error_handler();
    }

    static::assertSame(1, $calls);
    static::assertSame([], $warnings, '不应产生任何 PHP 警告: ' . json_encode($warnings));
  }

  /**
   * 测试注册负数监听次数限制 - 应抛出异常
   *
   * @return void
   */
  public function testNegativeLimitThrows(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->event->on('negLimit', fn() => null, -1);
  }

  /**
   * 测试枚举类作为监听器类 - 应抛出异常
   *
   * @return void
   */
  public function testEnumClassAsListenerHandleThrows(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->event->on('enumHandle', TestStringEvent::class);
  }
}
