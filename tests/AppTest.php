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

/**
 * 测试容器
 */
class AppTest extends TestCase
{
  protected App $app;

  /**
   * 测试从容器中获取依赖
   *
   * @return void
   */
  public function testGet()
  {
    /** @var Config $config */
    $config = $this->app->get('config');
    self::assertInstanceOf(Config::class, $config);
  }

  /**
   * 测试反射调用函数
   *
   * @return void
   */
  public function testInvokeFunction()
  {
    $fn = function (App $app, mixed $test) {
      return $test;
    };
    $result = $this->app->invokeFunction($fn, [$this->app, 'test']);
    self::assertEquals('test', $result);
  }

  /**
   * 测试创建一个依赖实例
   *
   * @return void
   */
  public function testMake()
  {
    $config = $this->app->make('config');
    self::assertInstanceOf(Config::class, $config);
  }

  /**
   * 测试绑定一个依赖
   *
   * @return void
   */
  public function testBind()
  {
    $this->app->bind('test', function (App $app) {
      return $app;
    });
    $test = $this->app->make('test');
    self::assertInstanceOf(App::class, $test);
  }

  /**
   * 测试移除一个依赖钩子
   *
   * @return void
   */
  public function testRemoveHook()
  {
    $callback = function (MyTest $test) {
      throw new \Exception('收到了MyTest类被反射解析的回调');
    };
    $callback2 = function (MyTest $test) {
      echo '收到了MyTest类被反射解析的回调2' . PHP_EOL;
    };
    $id = $this->app->addHook(MyTest::class, $callback);
    $this->app->addHook(MyTest::class, $callback2);
    $this->app->removeHook(MyTest::class, $id);
    $this->testInvokeClass();
  }

  /**
   * 测试反射调用类
   *
   * @return void
   */
  public function testInvokeClass()
  {
    $instance = $this->app->invokeClass(MyTest::class);
    self::assertInstanceOf(MyTest::class, $instance);
  }

  /**
   * 测试反射调用方法
   *
   * @return void
   */
  public function testInvokeMethod()
  {
    $result = $this->app->invokeMethod([MyTest::class, 'test']);
    self::assertEquals('test', $result);
  }

  /**
   * 测试添加一个依赖钩子
   *
   * @return void
   */
  public function testAddHook()
  {
    $this->app->addHook(MyTest::class, function (MyTest $test, App $app) {
      echo '收到了MyTest类被反射解析的回调' . PHP_EOL;
      self::assertTrue(true);
    });
    $this->testInvokeClass();
  }

  /**
   * 测试判断一个依赖是否存在
   *
   * @return void
   */
  public function testHas()
  {
    $result = $this->app->has('config');
    self::assertTrue($result);
  }

  /**
   * 测试移除一个依赖
   *
   * @return void
   */
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

  /**
   * @return void
   */
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
