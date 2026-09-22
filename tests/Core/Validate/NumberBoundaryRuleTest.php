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
use Viswoole\Core\Validate\Rules\Between;
use Viswoole\Core\Validate\Rules\Max;
use Viswoole\Core\Validate\Rules\Min;
use Viswoole\Core\Validate\Rules\NotBetween;

/**
 * 数值边界规则（Between/NotBetween/Min/Max）转换策略回归测试
 *
 * 修复前缺陷：四个规则的值转换类型由边界字面量类型决定——
 * `Between(0, 100)`（整型字面量）时 float 入参 0.5 走 intval 截断为 0
 * 且校验通过返回 int 0（业务拿到错误值）；必须写 Between(0.0, 100.0)
 * 才走 floatval。比较与返回均应基于值本身的数值形态，与边界字面量类型解耦。
 *
 * 修复后语义：$value + 0 数值化——int 保持 int、float 保持 float、
 * 数字字符串转为数值；返回形态跟随输入值（内置类型转换已按参数声明
 * 类型先行归一，两者天然匹配）。
 */
class NumberBoundaryRuleTest extends TestCase
{
  // ---------- Between ----------

  public function testBetweenIntBoundsPreserveFloatValue(): void
  {
    $rule = new Between(0, 100);
    self::assertSame(0.5, $rule->validate('0.5'), '字符串小数不应被 intval 截断');
    self::assertSame(0.5, $rule->validate(0.5));
    self::assertSame(100, $rule->validate(100));
    self::assertSame(0, $rule->validate(0));
  }

  public function testBetweenFloatBoundsStillWork(): void
  {
    $rule = new Between(0.0, 100.0);
    self::assertSame(0.5, $rule->validate('0.5'));
    self::assertSame(5, $rule->validate('5'));
    self::assertSame(5.0, $rule->validate(5.0));
  }

  public function testBetweenOutOfRangeThrows(): void
  {
    $rule = new Between(1, 10);
    $this->expectException(ValidateException::class);
    $rule->validate(11);
  }

  // ---------- NotBetween ----------

  public function testNotBetweenIntBoundsPreserveFloatValue(): void
  {
    $rule = new NotBetween(100, 200);
    self::assertSame(0.5, $rule->validate('0.5'));
  }

  public function testNotBetweenInRangeThrows(): void
  {
    $rule = new NotBetween(1, 10);
    $this->expectException(ValidateException::class);
    $rule->validate(5);
  }

  // ---------- Min ----------

  public function testMinIntBoundPreservesFloatValue(): void
  {
    $rule = new Min(0);
    self::assertSame(0.5, $rule->validate('0.5'));
    self::assertSame(10, $rule->validate(10));
  }

  public function testMinBelowBoundThrows(): void
  {
    $rule = new Min(1);
    $this->expectException(ValidateException::class);
    $rule->validate(0);
  }

  // ---------- Max ----------

  public function testMaxIntBoundPreservesFloatValue(): void
  {
    $rule = new Max(100);
    self::assertSame(99.5, $rule->validate('99.5'));
    self::assertSame(50, $rule->validate(50));
  }

  public function testMaxAboveBoundThrows(): void
  {
    $rule = new Max(100);
    $this->expectException(ValidateException::class);
    $rule->validate(101);
  }

  // ---------- 非数值输入 ----------

  public function testNonNumericThrows(): void
  {
    $this->expectException(ValidateException::class);
    (new Between(0, 100))->validate('abc');
  }
}
