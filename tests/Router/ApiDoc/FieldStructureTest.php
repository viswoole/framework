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
use ReflectionFunction;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\HttpServer\AutoInject\InjectPost;
use Viswoole\Router\ApiDoc\ParamParseTool;
use Viswoole\Router\ApiDoc\Structure\ArrayTypeStructure;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;

/**
 * 字段类型解析测试
 *
 * 覆盖 FieldStructure 对内置类型的映射，
 * 重点验证纯 array 类型应解析为 Array<mixed> 而非 mixed
 */
class FieldStructureTest extends TestCase
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
   * 测试纯 array 参数解析为 Array<mixed>
   *
   * 回归场景：#[InjectPost] array $apis 曾被误解析为 mixed
   *
   * @return void
   */
  public function testPlainArrayParamParsesAsArrayType(): void
  {
    $result = ParamParseTool::parse([ArrayTypeFixtureController::class, 'assignApis']);
    $apis = $result['params']['body']['apis'] ?? null;
    self::assertNotNull($apis, 'body 参数中应包含 apis 字段');
    self::assertArrayHasKey('Array<mixed>', $apis->types, '纯 array 类型应解析为 Array<mixed>');
    self::assertSame('array', $apis->types['Array<mixed>']->getType());
    self::assertInstanceOf(ArrayTypeStructure::class, $apis->types['Array<mixed>']);
    // int 参数不受影响
    self::assertArrayHasKey('int', $result['params']['body']['role_id']->types);
  }

  /**
   * 测试可空 array 参数同样解析为数组类型
   *
   * @return void
   */
  public function testNullableArrayParamParsesAsArrayType(): void
  {
    $result = ParamParseTool::parse([ArrayTypeFixtureController::class, 'nullableArray']);
    $tags = $result['params']['body']['tags'] ?? null;
    self::assertNotNull($tags);
    self::assertArrayHasKey('Array<mixed>', $tags->types);
    self::assertTrue($tags->allowNull);
  }

  /**
   * 测试直接以反射类型构造 FieldStructure 时的内置类型映射
   *
   * @return void
   */
  public function testBuiltinTypeMapping(): void
  {
    $closure = static function (
      int     $a,
      string  $b,
      bool    $c,
      float   $d,
      array   $e,
      ?array  $f,
      mixed   $g,
    ): void {
    };
    $parameters = (new ReflectionFunction($closure))->getParameters();
    $expected = [
      'a' => 'int',
      'b' => 'string',
      'c' => 'bool',
      'd' => 'float',
      // 纯 array 与 ?array 均应映射为 Array<mixed>
      'e' => 'Array<mixed>',
      'f' => 'Array<mixed>',
      'g' => 'mixed',
    ];
    foreach ($parameters as $parameter) {
      $field = new FieldStructure($parameter->getName(), type: $parameter->getType());
      self::assertArrayHasKey(
        $expected[$parameter->getName()],
        $field->types,
        "参数 \${$parameter->getName()} 类型映射不符合预期"
      );
    }
  }
}

/**
 * 数组类型解析测试夹具
 */
class ArrayTypeFixtureController
{
  /**
   * 模拟权限分配接口
   *
   * @param int $role_id 角色ID
   * @param array $apis 接口权限列表
   * @return array
   */
  public function assignApis(
    #[InjectPost] int   $role_id,
    #[InjectPost] array $apis,
  ): array
  {
    return [];
  }

  /**
   * 可空数组参数
   *
   * @param array|null $tags 标签列表
   * @return void
   */
  public function nullableArray(
    #[InjectPost] ?array $tags = null,
  ): void
  {
  }
}
