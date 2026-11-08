<?php

declare(strict_types=1);

/* 文件职责：锁定框架整数转换不得截断小数或经浮点数舍入雪花ID。 */

namespace Viswoole\Tests\Core\Validate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\BuiltinTypeValidate;

/**
 * 框架整数转换精度契约
 */
final class IntegerValidationTest extends TestCase
{
  /**
   * 提供可精确表示的整数输入
   *
   * 保留整数科学计数法和带零小数的兼容语义，纯数字不经过浮点中转。
   *
   * @return iterable<string,array{mixed,int}> 合法输入及期望整数
   */
  public static function validIntegers(): iterable
  {
    yield '雪花ID' => ['1941234567890123456', 1941234567890123456];
    yield '最大值' => ['9223372036854775807', PHP_INT_MAX];
    yield '最小值' => ['-9223372036854775808', PHP_INT_MIN];
    yield '科学整数' => ['1e5', 100000];
    yield '精确科学边界' => ['9.223372036854775807e18', PHP_INT_MAX];
    yield '带零小数' => ['1.000', 1];
    yield '科学小数整数' => ['0.00100e3', 1];
    yield '前导正号和零' => [' +000123 ', 123];
    yield '负科学整数' => ['-12e1', -120];
    yield '浮点整数' => [2.0, 2];
    yield '零' => ['0e999999999999999999999999', 0];
  }

  /**
   * 转换合法整数且不损失精度
   *
   * 两个公开整数入口必须采用同一规则。
   *
   * @param mixed $value 输入值
   * @param int $expected 期望整数
   * @return void 转换结果精确一致
   */
  #[DataProvider('validIntegers')]
  public function testConvertsExactInteger(mixed $value, int $expected): void
  {
    self::assertSame($expected, BuiltinTypeValidate::int($value));
    self::assertSame($expected, BuiltinTypeValidate::integer($value));
  }

  /**
   * 提供必须拒绝的非整数及溢出值
   *
   * 微小小数尾部不能被float舍入后伪装成整数。
   *
   * @return iterable<string,array{mixed}> 非法整数输入
   */
  public static function invalidIntegers(): iterable
  {
    foreach (['1.5', '-1.5', '.5', '1e-1', '1.0000000000000000001', '9223372036854775807.1',
      '9223372036854775808', '1e99999999999999999999', '1e-99999999999999999999'] as $value) {
      yield $value => [$value];
    }
    yield '浮点小数' => [1.5];
    yield '无穷大' => [INF];
    yield '非数' => [NAN];
  }

  /**
   * 拒绝不可精确转换的整数输入
   *
   * 拒绝发生在框架类型转换阶段，业务ID规则无需补偿原始输入丢失。
   *
   * @param mixed $value 非法输入
   * @return void 校验异常符合预期
   * @throws ValidateException 输入不是可表示的整数
   */
  #[DataProvider('invalidIntegers')]
  public function testRejectsNonInteger(mixed $value): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::int($value);
  }
}
