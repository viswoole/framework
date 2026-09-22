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
use Viswoole\Core\Validate\BuiltinTypeValidate;

/**
 * int 类型校验超范围拒绝回归测试
 *
 * 修复前缺陷：BuiltinTypeValidate::int 对纯整数字符串直接 (int) 强转、对科学
 * 计数法字符串经 (int)(float) 中转——超出 int64 范围的数字字符串被静默钳制为
 * PHP_INT_MAX / PHP_INT_MIN，且该内置转换发生在自定义验证规则之前，导致业务侧
 * 「超范围拒绝」类自定义规则（如雪花 ID 校验）被整体绕过，恶意入参命中错误记录。
 *
 * 修复后：强转前做数值范围校验，超出 int 可表示范围抛 ValidateException；
 * 边界值（PHP_INT_MAX / PHP_INT_MIN）与前导零、范围内科学计数法行为不变。
 */
class BuiltinIntRangeTest extends TestCase
{
  /**
   * 超出 int64 上界的数字字符串应抛异常而非钳制为 PHP_INT_MAX
   */
  public function testOutOfRangePositiveStringThrows(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::int('9223372036854775808');
  }

  /**
   * 超出 int64 下界的数字字符串应抛异常而非钳制为 PHP_INT_MIN
   */
  public function testOutOfRangeNegativeStringThrows(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::int('-9223372036854775809');
  }

  /**
   * 远超 int64 的长数字字符串应抛异常
   */
  public function testFarOutOfRangeStringThrows(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::int('99999999999999999999999999');
  }

  /**
   * 科学计数法表示的超范围数值应抛异常（修复前 (int)(float) 钳制）
   */
  public function testScientificNotationOutOfRangeThrows(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::int('1e30');
  }

  /**
   * 边界值 PHP_INT_MAX 正常转换
   */
  public function testMaxIntStringAccepted(): void
  {
    self::assertSame(PHP_INT_MAX, BuiltinTypeValidate::int('9223372036854775807'));
  }

  /**
   * 边界值 PHP_INT_MIN 正常转换（负界比正界多一位）
   */
  public function testMinIntStringAccepted(): void
  {
    self::assertSame(PHP_INT_MIN, BuiltinTypeValidate::int('-9223372036854775808'));
  }

  /**
   * 前导零的边界值正常转换
   */
  public function testLeadingZeroIntStringAccepted(): void
  {
    self::assertSame(PHP_INT_MAX, BuiltinTypeValidate::int('0009223372036854775807'));
    self::assertSame(123, BuiltinTypeValidate::int('0123'));
  }

  /**
   * 范围内的科学计数法行为不变（回归锁定）
   */
  public function testScientificNotationInRangeStillConverts(): void
  {
    self::assertSame(100000, BuiltinTypeValidate::int('1e5'));
  }

  /**
   * int64 范围内的常规雪花 ID 字符串行为不变（回归锁定）
   */
  public function testSnowflakeIdInRangeStillConverts(): void
  {
    self::assertSame(1941234567890124555, BuiltinTypeValidate::int('1941234567890124555'));
  }
}
