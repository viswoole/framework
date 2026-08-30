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

use Attribute;
use PHPUnit\Framework\TestCase;
use Viswoole\Router\ApiDoc\Annotation\IgnoreGlobal;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\ParamParseTool;

/**
 * Returned 子类注解支持测试
 *
 * 覆盖 #[Returned] 子类注解（IS_INSTANCEOF 收集）的场景：
 * 子类实例可被收集、与父类注解可混用、结构解析与排序语义不变，
 * 支撑项目级统一响应信封包装器的合法用法
 */
class ReturnedSubclassTest extends TestCase
{
  /**
   * 子类注解实例应被收集且保持子类类型
   *
   * @return void
   */
  public function testSubclassAttributeIsCollected(): void
  {
    $result = ParamParseTool::parse([ReturnedSubclassFixture::class, 'withSubclass']);
    self::assertCount(1, $result['returned']);
    $item = $result['returned'][0];
    self::assertInstanceOf(OkEnvelope::class, $item);
    self::assertInstanceOf(Returned::class, $item);
    // 信封包装语义：title 透传，data 已被子类构造为标准信封结构
    self::assertSame('子类成功响应', $item->title);
    self::assertSame(200, $item->statusCode);
  }

  /**
   * 子类与父类注解混用时应全部收集并按 sort 降序排列
   *
   * @return void
   */
  public function testSubclassMixedWithParent(): void
  {
    $result = ParamParseTool::parse([ReturnedSubclassFixture::class, 'mixed']);
    self::assertCount(2, $result['returned']);
    // sort 降序：信封（默认 0）在前，低优先级错误响应（-1）在后
    self::assertInstanceOf(OkEnvelope::class, $result['returned'][0]);
    self::assertSame('混用-错误响应', $result['returned'][1]->title);
  }

  /**
   * 子类注解的 data 结构应与父类一致地完成解析
   *
   * @return void
   */
  public function testSubclassDataStructureParsed(): void
  {
    $result = ParamParseTool::parse([ReturnedSubclassFixture::class, 'withSubclass']);
    $data = $result['returned'][0]->data;
    // 子类包装后的信封键
    self::assertArrayHasKey('code', $data);
    self::assertArrayHasKey('message', $data);
    self::assertSame(0, $data['code']);
  }
}

/**
 * 测试用统一成功信封注解（模拟项目级 Returned 子类的典型用法）
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class OkEnvelope extends Returned
{
  /**
   * @param string $title 标题
   * @param array $data 业务数据（自动包装为 code/message 信封）
   */
  public function __construct(string $title, array $data)
  {
    parent::__construct(
      title: $title,
      data : [
        'code|业务码' => 0,
        'message|提示信息' => 'ok',
        'data' => $data,
      ],
    );
  }
}

/**
 * Returned 子类测试夹具
 */
class ReturnedSubclassFixture
{
  /**
   * 仅使用子类注解
   *
   * IgnoreGlobal 排除全局返回声明，使断言与宿主项目配置无关
   *
   * @return void
   */
  #[IgnoreGlobal]
  #[OkEnvelope('子类成功响应', ['id' => 1])]
  public function withSubclass(): void
  {
  }

  /**
   * 子类与父类注解混用（子类默认 sort=0，父类显式 -1 验证排序）
   *
   * @return void
   */
  #[IgnoreGlobal]
  #[OkEnvelope('混用-成功响应', ['id' => 1])]
  #[Returned('混用-错误响应', ['code' => 1, 'message' => '错误'], sort: -1)]
  public function mixed(): void
  {
  }
}
