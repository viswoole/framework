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

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\HttpServer\AutoInject\InjectPost;
use Viswoole\Router\ApiDoc\DocTypeParser;
use Viswoole\Router\ApiDoc\ParamParseTool;
use Viswoole\Router\ApiDoc\Structure\ArrayTypeStructure;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\ObjectStructure;

/**
 * 文档注释类型声明解析测试
 *
 * 覆盖 DocTypeParser 对 PHPStan 风格类型字符串的解析，
 * 以及 ParamParseTool 对 @param 类型声明的融合（优先于反射类型）
 */
class DocTypeParserTest extends TestCase
{
  /**
   * 清空全局 API 文档配置，避免其他测试残留污染
   *
   * @return void
   */
  protected function setUp(): void
  {
    /** @var Config $config */
    $config = App::factory()->get('config');
    foreach (['header', 'query', 'body', 'returned'] as $source) {
      $config->set("router.api_doc.$source", []);
    }
  }

  /**
   * 测试基础数组后缀语法
   *
   * @return void
   */
  public function testParseBuiltinArraySuffix(): void
  {
    $types = DocTypeParser::parse('int[]');
    self::assertArrayHasKey('Array<int>', $types);
    self::assertInstanceOf(ArrayTypeStructure::class, $types['Array<int>']);
    self::assertSame('array', $types['Array<int>']->getType());
  }

  /**
   * 测试多维数组逐层解析
   *
   * @return void
   */
  public function testParseMultiDimensionalArray(): void
  {
    $types = DocTypeParser::parse('string[][]');
    self::assertArrayHasKey('Array<Array<string>>', $types);
  }

  /**
   * 测试联合类型拆分与括号包裹的联合数组
   *
   * @return void
   */
  public function testParseUnionType(): void
  {
    $types = DocTypeParser::parse('int|string');
    self::assertArrayHasKey('int', $types);
    self::assertArrayHasKey('string', $types);

    $arrayTypes = DocTypeParser::parse('(int|string)[]');
    self::assertArrayHasKey('Array<int | string>', $arrayTypes);
  }

  /**
   * 测试可空前缀与null成员被剥离（可空性由参数allowNull表达）
   *
   * @return void
   */
  public function testParseNullablePrefix(): void
  {
    self::assertArrayHasKey('int', DocTypeParser::parse('?int'));
    self::assertArrayHasKey('int', DocTypeParser::parse('int|null'));
  }

  /**
   * 测试关联数组结构解析（含可选字段与嵌套结构）
   *
   * @return void
   */
  public function testParseArrayShape(): void
  {
    $types = DocTypeParser::parse('array{id: int, name?: string, tags: string[]}');
    self::assertArrayHasKey('object', $types);
    self::assertInstanceOf(ObjectStructure::class, $types['object']);
    /** @var FieldStructure[] $props 运行时为FieldStructure列表，注解辅助IDE推断 */
    $props = $types['object']->properties;
    self::assertCount(3, $props);
    // 必填字段
    self::assertSame('id', $props[0]->name);
    self::assertArrayHasKey('int', $props[0]->types);
    self::assertFalse($props[0]->allowNull);
    // 可选字段（?后缀）
    self::assertSame('name', $props[1]->name);
    self::assertTrue($props[1]->allowNull);
    // 嵌套数组字段
    self::assertSame('tags', $props[2]->name);
    self::assertArrayHasKey('Array<string>', $props[2]->types);
  }

  /**
   * 测试深层嵌套关联结构
   *
   * @return void
   */
  public function testParseNestedArrayShape(): void
  {
    $types = DocTypeParser::parse('array{user: array{name: string}}');
    $user = $types['object']->properties[0];
    self::assertInstanceOf(FieldStructure::class, $user);
    self::assertSame('user', $user->name);
    self::assertArrayHasKey('object', $user->types);
    $inner = $user->types['object']->properties[0];
    self::assertInstanceOf(FieldStructure::class, $inner);
    self::assertSame('name', $inner->name);
    self::assertArrayHasKey('string', $inner->types);
  }

  /**
   * 测试关联结构数组的包装
   *
   * @return void
   */
  public function testParseArrayOfShape(): void
  {
    $types = DocTypeParser::parse('array{id: int}[]');
    self::assertArrayHasKey('Array<object>', $types);
  }

  /**
   * 测试枚举与类名的解析（须完全限定名称）
   *
   * @return void
   */
  public function testParseClassAndEnum(): void
  {
    $enumTypes = DocTypeParser::parse('\\Viswoole\\Tests\\Router\\ApiDoc\\DocTypeEnum[]');
    self::assertArrayHasKey('Array<DocTypeEnum>', $enumTypes);

    $classTypes = DocTypeParser::parse('\\Viswoole\\Tests\\Router\\ApiDoc\\DocTypeDto');
    self::assertArrayHasKey('DocTypeDto', $classTypes);
  }

  /**
   * 测试未知类型整体回退（返回空数组由调用方回退反射类型）
   *
   * @return void
   */
  public function testParseUnknownTypeReturnsEmpty(): void
  {
    self::assertSame([], DocTypeParser::parse('Unknown\\Class[]'));
    self::assertSame([], DocTypeParser::parse('int|Unknown\\Class'));
    self::assertSame([], DocTypeParser::parse(''));
  }

  /**
   * 测试ParamParseTool融合docblock类型声明
   *
   * @return void
   */
  public function testParamParseToolMergesDocType(): void
  {
    $result = ParamParseTool::parse([DocTypeFixtureController::class, 'create']);
    $body = $result['params']['body'];
    // int[] 数组元素类型
    self::assertArrayHasKey('Array<int>', $body['apis']->types);
    // array{} 关联结构
    self::assertArrayHasKey('object', $body['data']->types);
    self::assertSame('id', $body['data']->types['object']->properties[0]->name);
    // 枚举数组（FQCN）
    self::assertArrayHasKey('Array<DocTypeEnum>', $body['statuses']->types);
    // 无docblock声明的参数回退反射类型 Array<mixed>
    self::assertArrayHasKey('Array<mixed>', $body['extra']->types);
    // 描述文本提取不受类型声明影响
    self::assertSame('接口权限列表', $body['apis']->description);
    self::assertSame('角色信息', $body['data']->description);
  }
}

/**
 * 类型解析测试用枚举
 */
enum DocTypeEnum
{
  case Enabled;
  case Disabled;
}

/**
 * 类型解析测试用类
 */
class DocTypeDto
{
}

/**
 * docblock类型声明融合测试夹具
 *
 * 注意：类名/枚举名声明须使用完全限定名称，解析器不处理 use 导入短名
 */
class DocTypeFixtureController
{
  /**
   * @param int[] $apis 接口权限列表
   * @param array{id: int, name?: string} $data 角色信息
   * @param \Viswoole\Tests\Router\ApiDoc\DocTypeEnum[] $statuses 状态列表
   * @param array $extra 附加数据
   * @return array
   */
  public function create(
    #[InjectPost] array $apis,
    #[InjectPost] array $data,
    #[InjectPost] array $statuses,
    #[InjectPost] array $extra,
  ): array
  {
    return [];
  }
}
