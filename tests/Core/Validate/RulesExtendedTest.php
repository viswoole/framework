<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core\Validate;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\Rules\Alpha;
use Viswoole\Core\Validate\Rules\AlphaNumber;
use Viswoole\Core\Validate\Rules\Chinese;
use Viswoole\Core\Validate\Rules\Mobile;
use Viswoole\Core\Validate\Rules\IdCard;
use Viswoole\Core\Validate\Rules\Regex;
use Viswoole\Core\Validate\Rules\DateFormat;
use Viswoole\Core\Validate\Rules\NotInArray;

/**
 * 扩展验证规则测试
 *
 * 测试 Alpha、AlphaNumber、Chinese、Mobile、IdCard、Regex、DateFormat、NotInArray 规则
 */
class RulesExtendedTest extends TestCase
{
  // ─── Alpha 测试 ───────────────────────────────────────────────

  /**
   * 测试 Alpha 规则 - 纯字母字符串通过验证
   *
   * @return void
   */
  public function testAlphaValid(): void
  {
    $rule = new Alpha();
    static::assertEquals('AbCdEf', $rule->validate('AbCdEf'));
  }

  /**
   * 测试 Alpha 规则 - 含数字的字符串抛出异常
   *
   * @return void
   */
  public function testAlphaInvalid(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Alpha();
    $rule->validate('abc123');
  }

  /**
   * 测试 Alpha 规则 - 空字符串抛出异常
   *
   * @return void
   */
  public function testAlphaEmpty(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Alpha();
    $rule->validate('');
  }

  // ─── AlphaNumber 测试 ─────────────────────────────────────────

  /**
   * 测试 AlphaNumber 规则 - 字母数字组合通过验证
   *
   * @return void
   */
  public function testAlphaNumberValid(): void
  {
    $rule = new AlphaNumber();
    static::assertEquals('Viswoole2024', $rule->validate('Viswoole2024'));
  }

  /**
   * 测试 AlphaNumber 规则 - 含特殊字符抛出异常
   *
   * @return void
   */
  public function testAlphaNumberInvalid(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new AlphaNumber();
    $rule->validate('user@123');
  }

  // ─── Chinese 测试 ─────────────────────────────────────────────

  /**
   * 测试 Chinese 规则 - 纯汉字字符串通过验证
   *
   * @return void
   */
  public function testChineseValid(): void
  {
    $rule = new Chinese();
    static::assertEquals('你好世界', $rule->validate('你好世界'));
  }

  /**
   * 测试 Chinese 规则 - 含字母的字符串抛出异常
   *
   * @return void
   */
  public function testChineseInvalid(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Chinese();
    $rule->validate('Hello你好');
  }

  // ─── Mobile 测试 ──────────────────────────────────────────────

  /**
   * 测试 Mobile 规则 - 合法手机号通过验证
   *
   * @return void
   */
  public function testMobileValid(): void
  {
    $rule = new Mobile();
    // 1开头，第二位3-9，共11位
    static::assertEquals('13912345678', $rule->validate('13912345678'));
  }

  /**
   * 测试 Mobile 规则 - 非法手机号抛出异常
   *
   * @return void
   */
  public function testMobileInvalid(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Mobile();
    // 以2开头，第二位不在3-9范围
    $rule->validate('12345678901');
  }

  // ─── IdCard 测试 ──────────────────────────────────────────────

  /**
   * 测试 IdCard 规则 - 合法身份证号通过验证
   *
   * @return void
   */
  public function testIdCardValid(): void
  {
    $rule = new IdCard();
    // 11010519491231002X 是一个校验位正确的18位身份证号
    $validIdCard = '11010519491231002X';
    static::assertEquals($validIdCard, $rule->validate($validIdCard));
  }

  /**
   * 测试 IdCard 规则 - 校验位错误抛出异常
   *
   * @return void
   */
  public function testIdCardInvalidChecksum(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new IdCard();
    // 修改最后一位校验码，使校验位不匹配
    $rule->validate('110105194912310021');
  }

  /**
   * 测试 IdCard 规则 - 格式错误抛出异常
   *
   * @return void
   */
  public function testIdCardInvalidFormat(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new IdCard();
    $rule->validate('invalid-id-card');
  }

  // ─── Regex 测试 ───────────────────────────────────────────────

  /**
   * 测试 Regex 规则 - 匹配正则表达式通过验证
   *
   * @return void
   */
  public function testRegexMatch(): void
  {
    $rule = new Regex('/^\d{4}-\d{2}$/');
    static::assertEquals('2024-06', $rule->validate('2024-06'));
  }

  /**
   * 测试 Regex 规则 - 不匹配正则表达式抛出异常
   *
   * @return void
   */
  public function testRegexNotMatch(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Regex('/^\d{4}$/');
    $rule->validate('abc');
  }

  /**
   * 测试 Regex 规则 - 非字符串值抛出异常
   *
   * @return void
   */
  public function testRegexNonString(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Regex('/^\d+$/');
    $rule->validate(12345);
  }

  // ─── DateFormat 测试 ──────────────────────────────────────────

  /**
   * 测试 DateFormat 规则 - 正确日期格式通过验证
   *
   * @return void
   */
  public function testDateFormatValid(): void
  {
    $rule = new DateFormat('Y-m-d');
    static::assertEquals('2024-06-15', $rule->validate('2024-06-15'));
  }

  /**
   * 测试 DateFormat 规则 - 错误日期格式抛出异常
   *
   * @return void
   */
  public function testDateFormatInvalid(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new DateFormat('Y-m-d');
    // 格式不匹配：使用斜杠而非短横线
    $rule->validate('2024/06/15');
  }

  // ─── NotInArray 测试 ──────────────────────────────────────────

  /**
   * 测试 NotInArray 规则 - 值不在排除数组中通过验证
   *
   * @return void
   */
  public function testNotInArrayValid(): void
  {
    $rule = new NotInArray(['admin', 'root'], false);
    static::assertEquals('guest', $rule->validate('guest'));
  }

  /**
   * 测试 NotInArray 规则 - 值在排除数组中抛出异常
   *
   * @return void
   */
  public function testNotInArrayInvalid(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new NotInArray(['admin', 'root'], false);
    $rule->validate('admin');
  }
}
