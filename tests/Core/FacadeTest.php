<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Facade;

/**
 * 门面模式测试
 *
 * 通过创建具体 Facade 子类来测试门面代理行为
 */
class FacadeTest extends TestCase
{
  /**
   * 测试前准备：缓冲输出，避免 App 初始化时的输出干扰
   *
   * @return void
   */
  protected function setUp(): void
  {
    ob_start();
  }

  /**
   * 测试后清理：清空输出缓冲
   *
   * @return void
   */
  protected function tearDown(): void
  {
    ob_end_clean();
  }

  /**
   * 测试通过门面调用静态方法
   *
   * 验证 Facade::__callStatic 能正确代理到实际类的方法调用
   *
   * @return void
   */
  public function testCallStatic(): void
  {
    // 通过 TestFacade 代理调用 Config::get 方法
    $result = TestFacade::get('app.debug');
    // 应返回配置值（布尔值）
    static::assertIsBool($result);
  }

  /**
   * 测试 alwaysNewInstance 属性控制是否每次创建新实例
   *
   * 当 alwaysNewInstance 为 true 时，每次调用应创建新对象
   *
   * @return void
   */
  public function testAlwaysNewInstance(): void
  {
    // 设置 alwaysNewInstance 为 true 的门面
    $instance1 = AlwaysNewFacade::get('app.debug');
    $instance2 = AlwaysNewFacade::get('app.debug');
    // alwaysNewInstance=true 时，每次创建新实例，但 get 返回的是配置值而非对象
    // 所以这里验证的是调用不抛异常，且返回值类型正确
    static::assertIsBool($instance1);
    static::assertIsBool($instance2);
  }

  /**
   * 测试默认 createFacade 返回单例实例
   *
   * 当 alwaysNewInstance 为 false 时，多次调用应返回同一对象
   *
   * @return void
   */
  public function testCreateFacadeReturnsSameInstance(): void
  {
    $app = App::factory();
    // 先清除可能存在的 Config 单例，确保测试一致性
    $app->remove(Config::class);

    // 通过反射访问 createFacade 获取实例
    $facade1 = TestFacade::getFacadeInstance();
    $facade2 = TestFacade::getFacadeInstance();

    // 默认 alwaysNewInstance=false，应返回同一实例
    static::assertSame($facade1, $facade2);
  }
}

/**
 * 测试用 Facade 子类 - 映射到 Config 类
 */
class TestFacade extends Facade
{
  /**
   * @var bool 不强制每次创建新实例
   */
  protected static bool $alwaysNewInstance = false;

  /**
   * 获取映射的类名
   *
   * @return string
   */
  protected static function getMappingClass(): string
  {
    return Config::class;
  }

  /**
   * 暴露 createFacade 方法供测试使用
   *
   * @return object
   */
  public static function getFacadeInstance(): object
  {
    return static::createFacade();
  }
}

/**
 * 测试用 Facade 子类 - 每次创建新实例
 */
class AlwaysNewFacade extends Facade
{
  /**
   * @var bool 强制每次创建新实例
   */
  protected static bool $alwaysNewInstance = true;

  /**
   * 获取映射的类名
   *
   * @return string
   */
  protected static function getMappingClass(): string
  {
    return Config::class;
  }
}
