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
use Viswoole\Core\Validate\Rules\Alpha;
use Viswoole\Core\Validate\Rules\AlphaNumber;
use Viswoole\Core\Validate\Rules\Between;
use Viswoole\Core\Validate\Rules\Chinese;
use Viswoole\Core\Validate\Rules\DateAfter;
use Viswoole\Core\Validate\Rules\DateBefore;
use Viswoole\Core\Validate\Rules\DateFormat;
use Viswoole\Core\Validate\Rules\Filter;
use Viswoole\Core\Validate\Rules\IdCard;
use Viswoole\Core\Validate\Rules\InArray;
use Viswoole\Core\Validate\Rules\Length;
use Viswoole\Core\Validate\Rules\Max;
use Viswoole\Core\Validate\Rules\Min;
use Viswoole\Core\Validate\Rules\Mobile;
use Viswoole\Core\Validate\Rules\NotBetween;
use Viswoole\Core\Validate\Rules\NotInArray;
use Viswoole\Core\Validate\Rules\Regex;

/**
 * 验证规则测试
 */
class RulesTest extends TestCase
{
  /**
   * 测试 Alpha 规则 - 纯字母通过
   *
   * @return void
   */
  public function testAlphaSuccess(): void
  {
    $rule = new Alpha();
    static::assertEquals('HelloWorld', $rule->validate('HelloWorld'));
  }

  /**
   * 测试 Alpha 规则 - 包含数字应失败
   *
   * @return void
   */
  public function testAlphaFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Alpha();
    $rule->validate('Hello123');
  }

  /**
   * 测试 AlphaNumber 规则 - 字母数字组合通过
   *
   * @return void
   */
  public function testAlphaNumberSuccess(): void
  {
    $rule = new AlphaNumber();
    static::assertEquals('User123', $rule->validate('User123'));
  }

  /**
   * 测试 AlphaNumber 规则 - 包含特殊字符应失败
   *
   * @return void
   */
  public function testAlphaNumberFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new AlphaNumber();
    $rule->validate('User@123');
  }

  /**
   * 测试 Between 规则 - 在区间内通过
   *
   * @return void
   */
  public function testBetweenSuccess(): void
  {
    $rule = new Between(1, 100);
    static::assertEquals(50, $rule->validate(50));
  }

  /**
   * 测试 Between 规则 - 超出区间应失败
   *
   * @return void
   */
  public function testBetweenFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Between(1, 100);
    $rule->validate(101);
  }

  /**
   * 测试 Between 规则 - 边界值通过
   *
   * @return void
   */
  public function testBetweenBoundary(): void
  {
    $rule = new Between(1, 100);
    static::assertEquals(1, $rule->validate(1));
    static::assertEquals(100, $rule->validate(100));
  }

  /**
   * 测试 NotBetween 规则 - 在区间外通过
   *
   * @return void
   */
  public function testNotBetweenSuccess(): void
  {
    $rule = new NotBetween(1, 100);
    static::assertEquals(101, $rule->validate(101));
  }

  /**
   * 测试 NotBetween 规则 - 在区间内应失败
   *
   * @return void
   */
  public function testNotBetweenFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new NotBetween(1, 100);
    $rule->validate(50);
  }

  /**
   * 测试 Chinese 规则 - 纯汉字通过
   *
   * @return void
   */
  public function testChineseSuccess(): void
  {
    $rule = new Chinese();
    static::assertEquals('你好世界', $rule->validate('你好世界'));
  }

  /**
   * 测试 Chinese 规则 - 包含非汉字应失败
   *
   * @return void
   */
  public function testChineseFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Chinese();
    $rule->validate('Hello你好');
  }

  /**
   * 测试 Mobile 规则 - 有效手机号通过
   *
   * @return void
   */
  public function testMobileSuccess(): void
  {
    $rule = new Mobile();
    static::assertEquals('13800138000', $rule->validate('13800138000'));
  }

  /**
   * 测试 Mobile 规则 - 无效手机号应失败
   *
   * @return void
   */
  public function testMobileFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Mobile();
    $rule->validate('12345678901');
  }

  /**
   * 测试 IdCard 规则 - 有效身份证号通过
   *
   * @return void
   */
  public function testIdCardSuccess(): void
  {
    $rule = new IdCard();
    $validIdCard = '11010519491231002X';
    static::assertEquals($validIdCard, $rule->validate($validIdCard));
  }

  /**
   * 测试 IdCard 规则 - 无效身份证号应失败
   *
   * @return void
   */
  public function testIdCardFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new IdCard();
    $rule->validate('invalid-id-card');
  }

  /**
   * 测试 InArray 规则 - 值在数组中通过
   *
   * @return void
   */
  public function testInArraySuccess(): void
  {
    $rule = new InArray(['apple', 'banana', 'orange'], false);
    static::assertEquals('apple', $rule->validate('apple'));
  }

  /**
   * 测试 InArray 规则 - 值不在数组中应失败
   *
   * @return void
   */
  public function testInArrayFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new InArray(['apple', 'banana'], false);
    $rule->validate('grape');
  }

  /**
   * 测试 NotInArray 规则 - 值不在数组中通过
   *
   * @return void
   */
  public function testNotInArraySuccess(): void
  {
    $rule = new NotInArray(['apple', 'banana'], false);
    static::assertEquals('grape', $rule->validate('grape'));
  }

  /**
   * 测试 NotInArray 规则 - 值在数组中应失败
   *
   * @return void
   */
  public function testNotInArrayFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new NotInArray(['apple', 'banana'], false);
    $rule->validate('apple');
  }

  /**
   * 测试 Length 规则 - 字符串长度在范围内通过
   *
   * @return void
   */
  public function testLengthSuccessWithString(): void
  {
    $rule = new Length(2, 10);
    static::assertEquals('hello', $rule->validate('hello'));
  }

  /**
   * 测试 Length 规则 - 字符串过短应失败
   *
   * @return void
   */
  public function testLengthFailureWithStringTooShort(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Length(5, 10);
    $rule->validate('hi');
  }

  /**
   * 测试 Length 规则 - 数组长度在范围内通过
   *
   * @return void
   */
  public function testLengthSuccessWithArray(): void
  {
    $rule = new Length(2, 5);
    static::assertEquals([1, 2, 3], $rule->validate([1, 2, 3]));
  }

  /**
   * 测试 Length 规则 - 仅指定最小长度
   *
   * @return void
   */
  public function testLengthWithOnlyMin(): void
  {
    $rule = new Length(3);
    static::assertEquals('hello', $rule->validate('hello'));
  }

  /**
   * 测试 Min 规则 - 大于等于最小值通过
   *
   * @return void
   */
  public function testMinSuccess(): void
  {
    $rule = new Min(10);
    static::assertEquals(10, $rule->validate(10));
    static::assertEquals(20, $rule->validate(20));
  }

  /**
   * 测试 Min 规则 - 小于最小值应失败
   *
   * @return void
   */
  public function testMinFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Min(10);
    $rule->validate(5);
  }

  /**
   * 测试 Max 规则 - 小于等于最大值通过
   *
   * @return void
   */
  public function testMaxSuccess(): void
  {
    $rule = new Max(100);
    static::assertEquals(100, $rule->validate(100));
    static::assertEquals(50, $rule->validate(50));
  }

  /**
   * 测试 Max 规则 - 大于最大值应失败
   *
   * @return void
   */
  public function testMaxFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Max(100);
    $rule->validate(101);
  }

  /**
   * 测试 Regex 规则 - 匹配正则通过
   *
   * @return void
   */
  public function testRegexSuccess(): void
  {
    $rule = new Regex('/^\d{4}$/');
    static::assertEquals('2024', $rule->validate('2024'));
  }

  /**
   * 测试 Regex 规则 - 不匹配正则应失败
   *
   * @return void
   */
  public function testRegexFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Regex('/^\d{4}$/');
    $rule->validate('202');
  }

  /**
   * 测试 DateFormat 规则 - 正确格式通过
   *
   * @return void
   */
  public function testDateFormatSuccess(): void
  {
    $rule = new DateFormat('Y-m-d');
    static::assertEquals('2024-01-15', $rule->validate('2024-01-15'));
  }

  /**
   * 测试 DateFormat 规则 - 错误格式应失败
   *
   * @return void
   */
  public function testDateFormatFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new DateFormat('Y-m-d');
    $rule->validate('2024/01/15');
  }

  /**
   * 测试 DateAfter 规则 - 日期在指定日期之后通过
   *
   * @return void
   */
  public function testDateAfterSuccess(): void
  {
    $rule = new DateAfter('2024-01-01 00:00:00');
    static::assertEquals('2024-06-15 12:00:00', $rule->validate('2024-06-15 12:00:00'));
  }

  /**
   * 测试 DateAfter 规则 - 日期在指定日期之前应失败
   *
   * @return void
   */
  public function testDateAfterFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new DateAfter('2024-06-15 00:00:00');
    $rule->validate('2024-01-01 00:00:00');
  }

  /**
   * 测试 DateBefore 规则 - 日期在指定日期之前通过
   *
   * @return void
   */
  public function testDateBeforeSuccess(): void
  {
    $rule = new DateBefore('2024-12-31 00:00:00');
    static::assertEquals('2024-06-15 12:00:00', $rule->validate('2024-06-15 12:00:00'));
  }

  /**
   * 测试 DateBefore 规则 - 日期在指定日期之后应失败
   *
   * @return void
   */
  public function testDateBeforeFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new DateBefore('2024-01-01 00:00:00');
    $rule->validate('2024-06-15 00:00:00');
  }

  /**
   * 测试 Filter 规则 - 邮箱验证通过
   *
   * @return void
   */
  public function testFilterEmailSuccess(): void
  {
    $rule = new Filter(FILTER_VALIDATE_EMAIL);
    static::assertEquals('test@example.com', $rule->validate('test@example.com'));
  }

  /**
   * 测试 Filter 规则 - 邮箱验证失败
   *
   * @return void
   */
  public function testFilterEmailFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Filter(FILTER_VALIDATE_EMAIL);
    $rule->validate('invalid-email');
  }

  /**
   * 测试 Filter 规则 - URL 验证通过
   *
   * @return void
   */
  public function testFilterUrlSuccess(): void
  {
    $rule = new Filter(FILTER_VALIDATE_URL);
    static::assertEquals('https://example.com', $rule->validate('https://example.com'));
  }

  /**
   * 测试自定义错误消息
   *
   * @return void
   */
  public function testCustomMessage(): void
  {
    $rule = new Between(1, 10, '自定义错误消息');
    try {
      $rule->validate(20);
      static::fail('应抛出异常');
    } catch (ValidateException $e) {
      static::assertEquals('自定义错误消息', $e->getMessage());
    }
  }
}
