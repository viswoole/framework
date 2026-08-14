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

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Env;

/**
 * 环境变量管理测试
 */
class EnvTest extends TestCase
{
  /**
   * @var Env 环境变量实例
   */
  private Env $env;

  /**
   * 测试前初始化 Env 实例
   *
   * @return void
   */
  protected function setUp(): void
  {
    $this->env = App::factory()->make(Env::class);
  }

  /**
   * 测试获取环境变量 - 从 .env 文件加载的变量
   *
   * @return void
   */
  public function testGetFromEnvFile(): void
  {
    // .env 文件中定义了 APP_DEBUG=true
    $result = $this->env->get('APP_DEBUG');
    static::assertTrue($result);
  }

  /**
   * 测试获取环境变量 - 不存在的变量返回默认值
   *
   * @return void
   */
  public function testGetNonExistentReturnsDefault(): void
  {
    static::assertNull($this->env->get('NON_EXISTENT_VAR'));
    static::assertEquals('default', $this->env->get('NON_EXISTENT_VAR', 'default'));
  }

  /**
   * 测试获取环境变量 - 点号分隔符自动转换为下划线
   *
   * @return void
   */
  public function testGetWithDotNotation(): void
  {
    // app.debug 应该等同于 APP_DEBUG
    $result = $this->env->get('app.debug');
    static::assertTrue($result);
  }

  /**
   * 测试获取所有环境变量
   *
   * @return void
   */
  public function testGetAllEnv(): void
  {
    $all = $this->env->get();
    static::assertIsArray($all);
    static::assertArrayHasKey('APP_DEBUG', $all);
  }

  /**
   * 测试检测环境变量是否存在
   *
   * @return void
   */
  public function testHas(): void
  {
    static::assertTrue($this->env->has('APP_DEBUG'));
    static::assertFalse($this->env->has('NON_EXISTENT_VAR'));
  }

  /**
   * 测试设置环境变量
   *
   * @return void
   */
  public function testSet(): void
  {
    $this->env->set('TEST_CUSTOM_VAR', 'test_value');
    static::assertEquals('test_value', $this->env->get('TEST_CUSTOM_VAR'));
  }

  /**
   * 测试设置环境变量 - 点号分隔符自动转换
   *
   * @return void
   */
  public function testSetWithDotNotation(): void
  {
    $this->env->set('test.custom.var', 'value');
    static::assertEquals('value', $this->env->get('TEST_CUSTOM_VAR'));
  }

  /**
   * 测试布尔值自动转换 - true 值
   *
   * @return void
   */
  public function testBooleanConversionTrue(): void
  {
    $this->env->set('TEST_BOOL_TRUE', 'true');
    static::assertTrue($this->env->get('TEST_BOOL_TRUE'));

    $this->env->set('TEST_BOOL_ON', 'on');
    static::assertTrue($this->env->get('TEST_BOOL_ON'));
  }

  /**
   * 测试布尔值自动转换 - false 值
   *
   * @return void
   */
  public function testBooleanConversionFalse(): void
  {
    $this->env->set('TEST_BOOL_FALSE', 'false');
    static::assertFalse($this->env->get('TEST_BOOL_FALSE'));

    $this->env->set('TEST_BOOL_OFF', 'off');
    static::assertFalse($this->env->get('TEST_BOOL_OFF'));
  }

  /**
   * 测试解析含特殊字符注释的 .env 文件
   *
   * 回归验证：注释中的 ~、(、; 等特殊字符不应导致 .env 整体解析失败
   * （曾因使用 parse_ini_file 返回 false 使整份配置静默丢失）。
   *
   * @return void
   */
  public function testLoadEnvFileWithSpecialCommentChars(): void
  {
    $content = <<<'ENV'
# Redis 配置（0 到 15）: ~ 特殊符号不影响解析
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
# 双引号包裹，含转义序列
MESSAGE="hello\nworld"
# 单引号包裹，内容原样保留
RAW='a ( b ) ~ c'
# 未加引号的值，从首个 " #" 截断行内注释
DATABASE_URL=mysql://user:pass@host/db # 注释
export EXPORTED_VAR=exported_value

ENV;
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, $content);
    try {
      $method = new \ReflectionMethod(Env::class, 'load');
      $method->invoke($this->env, $file);
    } finally {
      @unlink($file);
    }

    static::assertEquals('127.0.0.1', $this->env->get('REDIS_HOST'));
    static::assertEquals('6379', $this->env->get('REDIS_PORT'));
    static::assertEquals('', $this->env->get('REDIS_PASSWORD'));
    static::assertEquals("hello\nworld", $this->env->get('MESSAGE'));
    static::assertEquals('a ( b ) ~ c', $this->env->get('RAW'));
    static::assertEquals('mysql://user:pass@host/db', $this->env->get('DATABASE_URL'));
    static::assertEquals('exported_value', $this->env->get('EXPORTED_VAR'));
  }

  /**
   * 测试 ArrayAccess 接口 - offsetExists
   *
   * @return void
   */
  public function testArrayAccessExists(): void
  {
    static::assertTrue(isset($this->env['APP_DEBUG']));
    static::assertFalse(isset($this->env['NON_EXISTENT_VAR']));
  }

  /**
   * 测试 ArrayAccess 接口 - offsetGet
   *
   * @return void
   */
  public function testArrayAccessGet(): void
  {
    static::assertTrue($this->env['APP_DEBUG']);
  }

  /**
   * 测试 ArrayAccess 接口 - offsetSet
   *
   * @return void
   */
  public function testArrayAccessSet(): void
  {
    $this->env['TEST_ARRAY_ACCESS'] = 'array_value';
    static::assertEquals('array_value', $this->env['TEST_ARRAY_ACCESS']);
  }

  /**
   * 测试 ArrayAccess 接口 - offsetUnset 不支持
   *
   * @return void
   */
  public function testArrayAccessUnsetThrowsException(): void
  {
    $this->expectException(\Exception::class);
    unset($this->env['APP_DEBUG']);
  }
}
