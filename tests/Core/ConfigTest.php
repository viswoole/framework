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
use Viswoole\Core\Config;

/**
 * 配置管理测试
 */
class ConfigTest extends TestCase
{
  /**
   * @var Config 配置实例
   */
  private Config $config;

  /**
   * 测试前初始化 Config 实例
   *
   * @return void
   */
  protected function setUp(): void
  {
    $this->config = App::factory()->make(Config::class);
  }

  /**
   * 测试获取所有配置
   *
   * @return void
   */
  public function testGetAllConfig(): void
  {
    $all = $this->config->get();
    static::assertIsArray($all);
    // 应该包含 app.php 配置文件的内容
    static::assertArrayHasKey('app', $all);
  }

  /**
   * 测试获取一级配置
   *
   * @return void
   */
  public function testGetFirstLevelConfig(): void
  {
    $appConfig = $this->config->get('app');
    static::assertIsArray($appConfig);
    static::assertArrayHasKey('debug', $appConfig);
  }

  /**
   * 测试使用点号分隔符获取嵌套配置
   *
   * @return void
   */
  public function testGetNestedConfigWithDotNotation(): void
  {
    $debug = $this->config->get('app.debug');
    static::assertIsBool($debug);
  }

  /**
   * 测试获取不存在的配置返回默认值
   *
   * @return void
   */
  public function testGetNonExistentConfigReturnsDefault(): void
  {
    static::assertNull($this->config->get('non.existent'));
    static::assertEquals('default', $this->config->get('non.existent', 'default'));
  }

  /**
   * 测试检测配置是否存在
   *
   * @return void
   */
  public function testHas(): void
  {
    static::assertTrue($this->config->has('app'));
    static::assertTrue($this->config->has('app.debug'));
    static::assertFalse($this->config->has('non.existent'));
  }

  /**
   * 测试设置配置
   *
   * @return void
   */
  public function testSet(): void
  {
    $this->config->set('test.custom', 'value');
    static::assertEquals('value', $this->config->get('test.custom'));
  }

  /**
   * 测试设置配置 - 覆盖已有配置
   *
   * @return void
   */
  public function testSetOverridesExistingConfig(): void
  {
    $this->config->set('app.debug', false);
    static::assertFalse($this->config->get('app.debug'));
  }

  /**
   * 测试设置配置 - 批量设置
   *
   * @return void
   */
  public function testSetBatch(): void
  {
    $this->config->set([
      'batch.key1' => 'value1',
      'batch.key2' => 'value2',
    ]);
    static::assertEquals('value1', $this->config->get('batch.key1'));
    static::assertEquals('value2', $this->config->get('batch.key2'));
  }

  /**
   * 测试 formatConfigKey 方法 - 默认区分大小写
   *
   * @return void
   */
  public function testFormatConfigKey(): void
  {
    // 默认 matchCase=true，原样返回
    static::assertEquals('AppName', $this->config->formatConfigKey('AppName'));
    static::assertEquals('app_debug', $this->config->formatConfigKey('app_debug'));
  }
}
