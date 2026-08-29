<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Exception\NotFoundException;

/**
 * 容器类测试
 *
 * 测试 Container 抽象类的绑定、解析、单例、ArrayAccess 等核心功能
 */
class ContainerTest extends TestCase
{
  private App $app;

  /**
   * 初始化容器实例并开启输出缓冲
   */
  protected function setUp(): void
  {
    $this->app = App::factory();
    ob_start();
  }

  /**
   * 清理输出缓冲
   */
  protected function tearDown(): void
  {
    ob_end_clean();
  }

  /**
   * 测试绑定接口到实现类并创建实例
   *
   * @return void
   */
  public function testBindAndMake(): void
  {
    $this->app->bind('test.stdclass', \stdClass::class);
    $instance = $this->app->make('test.stdclass');
    self::assertInstanceOf(\stdClass::class, $instance);
  }

  /**
   * 测试使用闭包绑定创建实例
   *
   * @return void
   */
  public function testMakeWithClosure(): void
  {
    $this->app->bind('test.closure', function () {
      $obj = new \stdClass();
      $obj->name = 'closure_test';
      return $obj;
    });
    $instance = $this->app->make('test.closure');
    self::assertInstanceOf(\stdClass::class, $instance);
    self::assertEquals('closure_test', $instance->name);
  }

  /**
   * 测试单例模式，多次 make 返回同一实例
   *
   * @return void
   */
  public function testSingleton(): void
  {
    $this->app->bind('test.singleton', \stdClass::class);
    $instance1 = $this->app->make('test.singleton');
    $instance2 = $this->app->make('test.singleton');
    self::assertSame($instance1, $instance2);
  }

  /**
   * 测试 setSingleInstance 设置单例
   *
   * @return void
   */
  public function testSetSingleInstance(): void
  {
    $obj = new \stdClass();
    $obj->value = 'single';
    // bind 传入对象实例会调用 setSingleInstance
    $this->app->bind('test.single_instance', $obj);
    $result = $this->app->make('test.single_instance');
    self::assertSame($obj, $result);
  }

  /**
   * 测试 has 方法检查绑定是否存在
   *
   * @return void
   */
  public function testHas(): void
  {
    self::assertFalse($this->app->has('test.not_exist'));
    $this->app->bind('test.exist', \stdClass::class);
    self::assertTrue($this->app->has('test.exist'));
  }

  /**
   * 测试 get 方法获取已绑定服务
   *
   * @return void
   */
  public function testGet(): void
  {
    $this->app->bind('test.get', \stdClass::class);
    $instance = $this->app->get('test.get');
    self::assertInstanceOf(\stdClass::class, $instance);
  }

  /**
   * 测试 get 获取未绑定服务抛出 NotFoundException
   *
   * @return void
   */
  public function testGetNotFoundException(): void
  {
    $this->expectException(NotFoundException::class);
    $this->app->get('test.not_bound_service');
  }

  /**
   * 测试 ArrayAccess offsetSet 和 offsetGet 接口
   *
   * @return void
   */
  public function testOffsetSetAndGet(): void
  {
    $this->app['test.array_access'] = \stdClass::class;
    $instance = $this->app['test.array_access'];
    self::assertInstanceOf(\stdClass::class, $instance);
  }

  /**
   * 测试 ArrayAccess offsetUnset 移除绑定
   *
   * @return void
   */
  public function testOffsetUnset(): void
  {
    $this->app->bind('test.offset_unset', \stdClass::class);
    self::assertTrue($this->app->has('test.offset_unset'));
    unset($this->app['test.offset_unset']);
    // offsetUnset 只移除 bindings，不移除 instances
    self::assertFalse($this->app->has('test.offset_unset'));
  }

  /**
   * 测试 invokeFunction 调用闭包
   *
   * @return void
   */
  public function testInvokeFunction(): void
  {
    $result = $this->app->invokeFunction(function (string $name): string {
      return 'Hello ' . $name;
    }, ['name' => 'World']);
    self::assertEquals('Hello World', $result);
  }

  /**
   * 回归守卫：同命名空间的闭包不得共享参数元数据缓存
   *
   * 命名空间内闭包的 getName() 返回 "命名空间{closure}"（而非纯 "{closure}"），
   * 若按名缓存会导致先解析的闭包（如 0 参闭包）污染后续闭包的参数注入。
   *
   * @return void
   */
  public function testClosureParamShapesNotSharedInNamespace(): void
  {
    // 先解析 0 参闭包（旧 bug 中会将空 shapes 缓存进同命名空间的共享键）
    $zero = $this->app->invokeFunction(function (): string {
      return 'zero';
    });
    self::assertSame('zero', $zero);
    // 后解析不同签名的闭包，必须按自身签名注入而非命中前者的缓存
    $result = $this->app->invokeFunction(function (string $name): string {
      return 'Hello ' . $name;
    }, ['name' => 'World']);
    self::assertSame('Hello World', $result);
  }

  /**
   * 测试 invokeMethod 调用类方法
   *
   * @return void
   */
  public function testInvokeMethod(): void
  {
    $result = $this->app->invokeMethod([ContainerTestMethod::class, 'greet']);
    self::assertEquals('hello', $result);
  }

  /**
   * 测试 invoke 调用闭包
   *
   * @return void
   */
  public function testInvokeClosure(): void
  {
    $result = $this->app->invoke(function (): string {
      return 'invoked';
    });
    self::assertEquals('invoked', $result);
  }

  /**
   * 测试 remove 移除绑定和实例
   *
   * @return void
   */
  public function testRemove(): void
  {
    $this->app->bind('test.remove', ContainerTestMethod::class);
    $this->app->make('test.remove');
    // make 后会产生单例实例
    self::assertTrue($this->app->hasInstance(ContainerTestMethod::class));
    $this->app->remove('test.remove');
    self::assertFalse($this->app->hasInstance(ContainerTestMethod::class));
  }

  /**
   * 测试 isCallable 检测可调用性
   *
   * @return void
   */
  public function testIsCallable(): void
  {
    // 闭包可调用
    self::assertTrue(App::isCallable(function () {}));
    // 类名可调用
    self::assertTrue(App::isCallable(\stdClass::class));
    // 数组形式可调用
    self::assertTrue(App::isCallable([ContainerTestMethod::class, 'greet']));
    // 不可调用返回 false
    self::assertFalse(App::isCallable('nonExistentFunction12345'));
  }
}

/**
 * 测试用辅助类
 */
class ContainerTestMethod
{
  public function greet(): string
  {
    return 'hello';
  }
}
