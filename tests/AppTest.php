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

namespace Viswoole\Tests;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Config;

class AppTest extends TestCase
{
  protected App $app;

  public function testGet()
  {
    /** @var Config $config */
    $config = $this->app->get('config');
    self::assertInstanceOf(Config::class, $config);
  }

  public function testInvokeFunction()
  {
    $fn = function (App $app, mixed $test) {
      return $test;
    };
    $result = $this->app->invokeFunction($fn, [$this->app, 'test']);
    self::assertEquals('test', $result);
  }

  public function testMake()
  {
    $config = $this->app->make('config');
    self::assertInstanceOf(Config::class, $config);
  }

  public function testBind()
  {
    $this->app->bind('test', function (App $app) {
      return $app;
    });
    $test = $this->app->make('test');
    self::assertInstanceOf(App::class, $test);
  }

  public function testRemoveHook()
  {
    $callback = function (MyTest $test, App $app) {
      throw new \Exception('收到了MyTest类被反射解析的回调');
    };
    $callback2 = function (MyTest $test, App $app) {
      echo '收到了MyTest类被反射解析的回调2' . PHP_EOL;
    };
    $this->app->addHook(MyTest::class, $callback);
    $this->app->addHook(MyTest::class, $callback2);
    $this->app->removeHook(MyTest::class, $callback);
    $this->testInvokeClass();
  }

  public function testInvokeClass()
  {
    $instance = $this->app->invokeClass(MyTest::class);
    self::assertInstanceOf(MyTest::class, $instance);
  }

  public function testInvokeMethod()
  {
    $result = $this->app->invokeMethod([MyTest::class, 'test']);
    self::assertEquals('test', $result);
  }

  public function testAddHook()
  {
    $this->app->addHook(MyTest::class, function (MyTest $test, App $app) {
      echo '收到了MyTest类被反射解析的回调' . PHP_EOL;
      self::assertTrue(true);
    });
    $this->testInvokeClass();
  }

  public function testHas()
  {
    $result = $this->app->has('config');
    self::assertTrue($result);
  }

  public function testRemove()
  {
    $this->app->bind('test', MyTest::class);
    $this->app->make('test');
    $result = $this->app->has(MyTest::class);
    self::assertTrue($result);
    $this->app->remove('test');
    $result = $this->app->has(MyTest::class);
    self::assertFalse($result);
  }

  protected function setUp(): void
  {
    $this->app = App::factory();
  }
}

class MyTest
{
  public function __construct(public App $app)
  {
  }

  public function test()
  {
    return 'test';
  }
}
