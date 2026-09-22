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

namespace Viswoole\Tests\Core\Validate;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\Rules\ArrayItem;
use Viswoole\Core\Validate\Rules\MapItem;
use Viswoole\Core\Validate\Type;

/**
 * ArrayItem 键保留回归 + MapItem 映射规则测试
 *
 * ArrayItem 修复前缺陷：逐元素校验用 $array[] 重建数组，丢失原始关联键名——
 * {"goodsId":"123"} 之类映射参数被退化为索引列表，键集合校验被迫下沉业务层。
 *
 * MapItem 为新增规则：声明「允许的键集合 + 各键值类型约束」，
 * 适用于扁平映射参数（与 ArrayItem 的纯列表场景互补）。
 */
class MapItemRuleTest extends TestCase
{
  // ---------- ArrayItem：关联键保留 ----------

  /**
   * ArrayItem 对关联数组应保留原始键名（修复前退化为索引列表）
   */
  public function testArrayItemPreservesAssociativeKeys(): void
  {
    $rule = new ArrayItem(Type::INT);
    $result = $rule->validate(['goodsId' => '123', 'num' => '5']);
    self::assertSame(['goodsId' => 123, 'num' => 5], $result);
  }

  /**
   * ArrayItem 对索引列表（纯列表场景）行为不变
   */
  public function testArrayItemListBehaviorUnchanged(): void
  {
    $rule = new ArrayItem(Type::INT);
    self::assertSame([1, 2, 3], $rule->validate(['1', '2', '3']));
  }

  /**
   * ArrayItem 非数组输入应抛异常
   */
  public function testArrayItemRejectsNonArray(): void
  {
    $rule = new ArrayItem(Type::INT);
    $this->expectException(ValidateException::class);
    $rule->validate('not-an-array');
  }

  // ---------- MapItem：映射规则 ----------

  /**
   * 声明键逐键校验并按类型转换，返回校验后映射
   *
   * 注意：fields 必须以位置数组参数传入——PHP 注解的命名参数须匹配构造器
   * 形参，键名无法作为命名参数使用
   */
  public function testMapItemValidatesDeclaredKeys(): void
  {
    $rule = new MapItem(['goodsId' => 'int', 'num' => Type::INT]);
    $result = $rule->validate(['goodsId' => '123', 'num' => '5']);
    self::assertSame(['goodsId' => 123, 'num' => 5], $result);
  }

  /**
   * 声明键缺失默认跳过（allowMissing=true）
   */
  public function testMapItemMissingKeySkippedByDefault(): void
  {
    $rule = new MapItem(['goodsId' => 'int', 'num' => 'int']);
    $result = $rule->validate(['goodsId' => '123']);
    self::assertSame(['goodsId' => 123], $result);
  }

  /**
   * allowMissing=false 时缺键应报错（消息含键名）
   */
  public function testMapItemMissingKeyThrowsWhenStrict(): void
  {
    $rule = new MapItem(['goodsId' => 'int', 'num' => 'int'], allowMissing: false);
    try {
      $rule->validate(['goodsId' => '123']);
      self::fail('缺键未抛出异常');
    } catch (ValidateException $e) {
      self::assertStringContainsString('num', $e->getMessage());
    }
  }

  /**
   * 未声明键默认报错（allowUnknown=false），消息含键名
   */
  public function testMapItemUnknownKeyThrowsByDefault(): void
  {
    $rule = new MapItem(['goodsId' => 'int']);
    try {
      $rule->validate(['goodsId' => '123', 'extra' => 'x']);
      self::fail('未知键未抛出异常');
    } catch (ValidateException $e) {
      self::assertStringContainsString('extra', $e->getMessage());
    }
  }

  /**
   * allowUnknown=true 时未声明键被静默丢弃，不进入返回值
   */
  public function testMapItemUnknownKeyDroppedWhenAllowed(): void
  {
    $rule = new MapItem(['goodsId' => 'int'], allowUnknown: true);
    $result = $rule->validate(['goodsId' => '123', 'extra' => 'x']);
    self::assertSame(['goodsId' => 123], $result);
  }

  /**
   * 子键类型校验失败时消息应包含键名定位
   */
  public function testMapItemChildFailureMessageContainsKey(): void
  {
    $rule = new MapItem(['goodsId' => 'int']);
    try {
      $rule->validate(['goodsId' => 'abc']);
      self::fail('子键类型错误未抛出异常');
    } catch (ValidateException $e) {
      self::assertStringContainsString('goodsId', $e->getMessage());
    }
  }

  /**
   * 支持管道符联合类型约束
   */
  public function testMapItemSupportsUnionTypeString(): void
  {
    $rule = new MapItem(['flag' => 'int|string']);
    self::assertSame(123, $rule->validate(['flag' => '123'])['flag']);
    self::assertSame('on', $rule->validate(['flag' => 'on'])['flag']);
  }

  /**
   * 自定义消息优先于默认文案
   */
  public function testMapItemCustomMessageOverrides(): void
  {
    $rule = new MapItem(['goodsId' => 'int'], message: '参数不合法');
    $this->expectException(ValidateException::class);
    $this->expectExceptionMessage('参数不合法');
    $rule->validate(['goodsId' => 'abc']);
  }

  /**
   * 非数组输入应抛异常
   */
  public function testMapItemRejectsNonArray(): void
  {
    $rule = new MapItem(['goodsId' => 'int']);
    $this->expectException(ValidateException::class);
    $rule->validate('not-an-array');
  }
}
