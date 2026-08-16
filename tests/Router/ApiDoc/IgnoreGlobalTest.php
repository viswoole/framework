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

declare(strict_types=1);

namespace Viswoole\Tests\Router\ApiDoc;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\HttpServer\AutoInject\InjectGet;
use Viswoole\HttpServer\AutoInject\InjectHeader;
use Viswoole\HttpServer\AutoInject\InjectPost;
use Viswoole\Router\ApiDoc\Annotation\IgnoreGlobal;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\ParamParseTool;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\Types;

/**
 * 全局参数排除注解测试
 *
 * 覆盖 #[IgnoreGlobal] 注解的构造校验、匹配语义，
 * 以及 ParamParseTool 对全局 header/query/body/returned 的排除过滤与局部参数保留
 */
class IgnoreGlobalTest extends TestCase
{
  /**
   * 注入全局 API 文档配置，模拟 verifyGlobalParams 归一化后的状态
   *
   * @return void
   */
  protected function setUp(): void
  {
    /** @var Config $config */
    $config = App::factory()->get('config');
    $config->set('router.api_doc.header', [
      'authorization' => new FieldStructure('authorization', '鉴权令牌', type: Types::String),
      'x-trace-id' => new FieldStructure('x-trace-id', '链路追踪ID', true, type: Types::String),
    ]);
    $config->set('router.api_doc.query', [
      'page' => new FieldStructure('page', '页码', default: 1, type: Types::Int),
    ]);
    $config->set('router.api_doc.body', [
      'sign' => new FieldStructure('sign', '数据签名', type: Types::String),
    ]);
    $config->set('router.api_doc.returned', [
      new Returned('通用错误响应', ['code' => 1, 'message' => '错误']),
    ]);
  }

  /**
   * 恢复全局配置为空，避免污染其他测试
   *
   * @return void
   */
  protected function tearDown(): void
  {
    /** @var Config $config */
    $config = App::factory()->get('config');
    $config->set('router.api_doc.header', []);
    $config->set('router.api_doc.query', []);
    $config->set('router.api_doc.body', []);
    $config->set('router.api_doc.returned', []);
  }

  /**
   * 测试注解参数归一化为数组
   *
   * @return void
   */
  public function testConstructorNormalizesStringArgs(): void
  {
    $rule = new IgnoreGlobal('header', 'authorization');
    self::assertSame([IgnoreGlobal::SOURCE_HEADER], $rule->source);
    self::assertSame(['authorization'], $rule->name);
  }

  /**
   * 测试默认构造为全部排除规则
   *
   * @return void
   */
  public function testDefaultConstructorExcludesAll(): void
  {
    $rule = new IgnoreGlobal();
    self::assertNull($rule->source);
    self::assertNull($rule->name);
    // 未限定来源与字段名时任意组合均命中
    self::assertTrue($rule->matches('header', 'authorization'));
    self::assertTrue($rule->matches('returned', null));
  }

  /**
   * 测试非法来源抛出异常
   *
   * @return void
   */
  public function testInvalidSourceThrowsException(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('source参数无效');
    new IgnoreGlobal('headers');
  }

  /**
   * 测试按来源与字段名组合匹配
   *
   * @return void
   */
  public function testMatchesBySourceAndName(): void
  {
    $rule = new IgnoreGlobal(['header', 'query'], ['authorization', 'page']);
    // 来源与字段均命中
    self::assertTrue($rule->matches('header', 'authorization'));
    self::assertTrue($rule->matches('query', 'page'));
    // 来源命中但字段未命中
    self::assertFalse($rule->matches('header', 'x-trace-id'));
    // 来源未命中
    self::assertFalse($rule->matches('body', 'sign'));
    // 未限定字段时来源命中即整源排除
    self::assertTrue($rule->matches('header', null));
  }

  /**
   * 测试无注解时全局参数全部保留
   *
   * @return void
   */
  public function testParseWithoutAnnotationKeepsGlobals(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'plain']);
    self::assertArrayHasKey('authorization', $result['params']['header']);
    self::assertArrayHasKey('x-trace-id', $result['params']['header']);
    self::assertArrayHasKey('page', $result['params']['query']);
    self::assertArrayHasKey('sign', $result['params']['body']);
    self::assertCount(1, $result['returned']);
  }

  /**
   * 测试 #[IgnoreGlobal] 排除全部全局配置
   *
   * @return void
   */
  public function testParseIgnoreAll(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'ignoreAll']);
    self::assertEmpty($result['params']['header']);
    self::assertEmpty($result['params']['query']);
    self::assertEmpty($result['params']['body']);
    self::assertEmpty($result['returned']);
  }

  /**
   * 测试 #[IgnoreGlobal('header')] 仅排除指定来源
   *
   * @return void
   */
  public function testParseIgnoreBySource(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'ignoreHeader']);
    self::assertEmpty($result['params']['header']);
    // 其他来源不受影响
    self::assertArrayHasKey('page', $result['params']['query']);
    self::assertArrayHasKey('sign', $result['params']['body']);
    self::assertCount(1, $result['returned']);
  }

  /**
   * 测试 #[IgnoreGlobal('header', 'authorization')] 精确排除单个全局字段
   *
   * @return void
   */
  public function testParseIgnoreBySourceAndName(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'ignoreAuthHeader']);
    self::assertArrayNotHasKey('authorization', $result['params']['header']);
    // 同来源其他字段保留
    self::assertArrayHasKey('x-trace-id', $result['params']['header']);
    self::assertArrayHasKey('page', $result['params']['query']);
  }

  /**
   * 测试仅按字段名排除（不限来源）
   *
   * @return void
   */
  public function testParseIgnoreByNameOnly(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'ignoreAuthByName']);
    self::assertArrayNotHasKey('authorization', $result['params']['header']);
    self::assertArrayHasKey('x-trace-id', $result['params']['header']);
  }

  /**
   * 测试排除全局返回声明
   *
   * @return void
   */
  public function testParseIgnoreReturned(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'ignoreReturned']);
    self::assertEmpty($result['returned']);
    // 请求参数不受影响
    self::assertArrayHasKey('authorization', $result['params']['header']);
  }

  /**
   * 测试多条排除规则叠加生效
   *
   * @return void
   */
  public function testParseMultipleRules(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'multiRules']);
    // 规则一：排除整个 header 来源
    self::assertEmpty($result['params']['header']);
    // 规则二：排除 query 中的 page 字段
    self::assertArrayNotHasKey('page', $result['params']['query']);
    // body 不受影响
    self::assertArrayHasKey('sign', $result['params']['body']);
  }

  /**
   * 测试类级注解对控制器内方法生效
   *
   * @return void
   */
  public function testClassLevelAnnotation(): void
  {
    $result = ParamParseTool::parse([IgnoreClassFixtureController::class, 'index']);
    self::assertEmpty($result['params']['header']);
    self::assertArrayHasKey('page', $result['params']['query']);
  }

  /**
   * 测试局部参数注解不受全局排除影响
   *
   * 被排除的接口仍可通过 #[InjectHeader] 等局部注解按需声明参数
   *
   * @return void
   */
  public function testLocalParamsSurviveGlobalIgnore(): void
  {
    $result = ParamParseTool::parse([IgnoreFixtureController::class, 'withLocalParams']);
    // 全局参数已全部排除，各来源仅剩局部声明
    self::assertArrayNotHasKey('authorization', $result['params']['header']);
    self::assertArrayNotHasKey('x-trace-id', $result['params']['header']);
    self::assertArrayNotHasKey('page', $result['params']['query']);
    self::assertArrayNotHasKey('sign', $result['params']['body']);
    // 局部声明的参数仍然解析
    self::assertArrayHasKey('token', $result['params']['header']);
    self::assertArrayHasKey('keyword', $result['params']['query']);
    self::assertArrayHasKey('password', $result['params']['body']);
  }
}

/**
 * 方法级排除规则测试夹具
 */
class IgnoreFixtureController
{
  /**
   * 无排除注解
   *
   * @return void
   */
  public function plain(): void
  {
  }

  /**
   * 排除全部全局配置
   */
  #[IgnoreGlobal]
  public function ignoreAll(): void
  {
  }

  /**
   * 仅排除全局请求头
   */
  #[IgnoreGlobal('header')]
  public function ignoreHeader(): void
  {
  }

  /**
   * 精确排除全局 header 中的 authorization
   */
  #[IgnoreGlobal('header', 'authorization')]
  public function ignoreAuthHeader(): void
  {
  }

  /**
   * 仅按名称排除任意来源的 authorization 字段
   */
  #[IgnoreGlobal(name: 'authorization')]
  public function ignoreAuthByName(): void
  {
  }

  /**
   * 排除全局返回声明
   */
  #[IgnoreGlobal('returned')]
  public function ignoreReturned(): void
  {
  }

  /**
   * 多规则叠加：排除整个 header 与 query 中的 page
   */
  #[IgnoreGlobal('header')]
  #[IgnoreGlobal('query', 'page')]
  public function multiRules(): void
  {
  }

  /**
   * 全局参数全部排除，但局部声明的参数应保留
   *
   * @param string $token 局部声明的鉴权头
   * @param string $keyword 局部声明的查询参数
   * @param string $password 局部声明的请求体参数
   * @return void
   */
  #[IgnoreGlobal]
  public function withLocalParams(
    #[InjectHeader] string $token = '',
    #[InjectGet] string   $keyword = '',
    #[InjectPost] string  $password = '',
  ): void
  {
  }
}

/**
 * 类级排除规则测试夹具
 */
#[IgnoreGlobal('header')]
class IgnoreClassFixtureController
{
  /**
   * 类级注解应对本方法生效
   *
   * @return void
   */
  public function index(): void
  {
  }
}
