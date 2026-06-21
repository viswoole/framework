<?php

declare(strict_types=1);

namespace Viswoole\Tests;

use PHPUnit\Framework\TestCase;
use Viswoole\Cache\CacheManager;
use Viswoole\Core\App;

/**
 * 助手函数测试
 *
 * 测试 helper.php 中定义的全局助手函数
 */
class HelperTest extends TestCase
{
  /**
   * 测试 app() 无参返回容器实例
   *
   * @return void
   */
  public function testAppReturnsContainer(): void
  {
    $result = app();
    self::assertInstanceOf(App::class, $result);
  }

  /**
   * 测试 app(null) 返回容器实例
   *
   * @return void
   */
  public function testAppWithNullReturnsContainer(): void
  {
    $result = app(null);
    self::assertInstanceOf(App::class, $result);
  }

  /**
   * 测试 app('config') 返回配置服务实例
   *
   * @return void
   */
  public function testAppWithServiceName(): void
  {
    $result = app('config');
    self::assertInstanceOf(\Viswoole\Core\Config::class, $result);
  }

  /**
   * 测试 env() 获取环境变量
   *
   * @return void
   */
  public function testEnv(): void
  {
    // 测试获取不存在的环境变量返回默认值
    $result = env('NON_EXISTENT_VAR_12345', 'default_value');
    self::assertEquals('default_value', $result);
  }

  /**
   * 测试 config() 获取配置
   *
   * @return void
   */
  public function testConfig(): void
  {
    // 测试获取不存在的配置返回默认值
    $result = config('non_existent_config_key_12345', 'default');
    self::assertEquals('default', $result);
  }

  /**
   * 测试 getRootPath() 返回项目根路径
   *
   * @return void
   */
  public function testGetRootPath(): void
  {
    $result = getRootPath();
    self::assertNotEmpty($result);
    self::assertDirectoryExists($result);
  }

  /**
   * 测试 getVersion() 返回版本号字符串
   *
   * @return void
   */
  public function testGetVersion(): void
  {
    $result = getVersion();
    self::assertIsString($result);
    self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $result);
  }

  /**
   * 测试 isDebug() 返回布尔值
   *
   * @return void
   */
  public function testIsDebug(): void
  {
    $result = isDebug();
    self::assertIsBool($result);
  }

  /**
   * 测试 make() 创建实例
   *
   * @return void
   */
  public function testMake(): void
  {
    $result = make(\stdClass::class);
    self::assertInstanceOf(\stdClass::class, $result);
  }

  /**
   * 测试 bind() 绑定接口到容器
   *
   * @return void
   */
  public function testBind(): void
  {
    bind('test.helper_bind', \stdClass::class);
    $result = make('test.helper_bind');
    self::assertInstanceOf(\stdClass::class, $result);
  }

  /**
   * 测试 invoke() 调用闭包
   *
   * @return void
   */
  public function testInvoke(): void
  {
    $result = invoke(function (string $name): string {
      return 'Hello ' . $name;
    }, ['name' => 'Viswoole']);
    self::assertEquals('Hello Viswoole', $result);
  }

  /**
   * 测试 cache() 无参返回 CacheManager 实例
   *
   * @return void
   */
  public function testCache(): void
  {
    $result = cache();
    self::assertInstanceOf(CacheManager::class, $result);
  }

  /**
   * 开启输出缓冲，避免 echo 输出导致 risky 标记
   */
  protected function setUp(): void
  {
    ob_start();
  }

  /**
   * 清理输出缓冲
   */
  protected function tearDown(): void
  {
    ob_end_clean();
  }
}
