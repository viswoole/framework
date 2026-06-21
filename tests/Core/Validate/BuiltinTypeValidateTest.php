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

use Closure;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\BuiltinTypeValidate;
use Viswoole\Core\Validate\Type;

/**
 * 内置类型校验测试
 */
class BuiltinTypeValidateTest extends TestCase
{
  /**
   * 测试 bool 校验 - 各种可转换值
   *
   * @return void
   */
  public function testBoolWithConvertibleValues(): void
  {
    static::assertTrue(BuiltinTypeValidate::bool(true));
    static::assertTrue(BuiltinTypeValidate::bool(1));
    static::assertTrue(BuiltinTypeValidate::bool('1'));
    static::assertTrue(BuiltinTypeValidate::bool('true'));
    static::assertTrue(BuiltinTypeValidate::bool('yes'));
    static::assertTrue(BuiltinTypeValidate::bool('on'));

    static::assertFalse(BuiltinTypeValidate::bool(false));
    static::assertFalse(BuiltinTypeValidate::bool(0));
    static::assertFalse(BuiltinTypeValidate::bool('0'));
    static::assertFalse(BuiltinTypeValidate::bool('false'));
    static::assertFalse(BuiltinTypeValidate::bool('no'));
    static::assertFalse(BuiltinTypeValidate::bool('off'));
  }

  /**
   * 测试 bool 校验 - 不可转换值应抛出异常
   *
   * @return void
   */
  public function testBoolWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::bool('invalid');
  }

  /**
   * 测试 boolean 方法（bool 别名）
   *
   * @return void
   */
  public function testBoolean(): void
  {
    static::assertTrue(BuiltinTypeValidate::boolean('true'));
    static::assertFalse(BuiltinTypeValidate::boolean('false'));
  }

  /**
   * 测试 int 校验 - 数字字符串自动转换
   *
   * @return void
   */
  public function testIntWithNumericString(): void
  {
    static::assertEquals(123, BuiltinTypeValidate::int('123'));
    static::assertEquals(123, BuiltinTypeValidate::int(123));
  }

  /**
   * 测试 int 校验 - 非数值应抛出异常
   *
   * @return void
   */
  public function testIntWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::int('abc');
  }

  /**
   * 测试 integer 方法（int 别名）
   *
   * @return void
   */
  public function testInteger(): void
  {
    static::assertEquals(42, BuiltinTypeValidate::integer('42'));
  }

  /**
   * 测试 float 校验 - 数字字符串自动转换
   *
   * @return void
   */
  public function testFloatWithNumericString(): void
  {
    static::assertEquals(1.5, BuiltinTypeValidate::float('1.5'));
    static::assertEquals(1.5, BuiltinTypeValidate::float(1.5));
  }

  /**
   * 测试 float 校验 - 非数值应抛出异常
   *
   * @return void
   */
  public function testFloatWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::float('abc');
  }

  /**
   * 测试 double 方法（float 别名）
   *
   * @return void
   */
  public function testDouble(): void
  {
    static::assertEquals(3.14, BuiltinTypeValidate::double('3.14'));
  }

  /**
   * 测试 string 校验
   *
   * @return void
   */
  public function testString(): void
  {
    static::assertEquals('hello', BuiltinTypeValidate::string('hello'));
  }

  /**
   * 测试 string 校验 - 非字符串应抛出异常
   *
   * @return void
   */
  public function testStringWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::string(123);
  }

  /**
   * 测试 array 校验
   *
   * @return void
   */
  public function testArray(): void
  {
    $result = BuiltinTypeValidate::array([1, 2, 3]);
    static::assertEquals([1, 2, 3], $result);
  }

  /**
   * 测试 array 校验 - 非数组应抛出异常
   *
   * @return void
   */
  public function testArrayWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::array('not-array');
  }

  /**
   * 测试 object 校验 - 关联数组自动转换
   *
   * @return void
   */
  public function testObjectWithAssociativeArray(): void
  {
    $result = BuiltinTypeValidate::object(['name' => 'test']);
    static::assertIsObject($result);
    static::assertEquals('test', $result->name);
  }

  /**
   * 测试 object 校验 - 对象直接返回
   *
   * @return void
   */
  public function testObjectWithObject(): void
  {
    $obj = new \stdClass();
    $obj->name = 'test';
    $result = BuiltinTypeValidate::object($obj);
    static::assertSame($obj, $result);
  }

  /**
   * 测试 object 校验 - 非对象非关联数组应抛出异常
   *
   * @return void
   */
  public function testObjectWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::object('string');
  }

  /**
   * 测试 null 校验 - 仅 null 返回 null
   *
   * @return void
   */
  public function testNullWithEmptyValue(): void
  {
    static::assertNull(BuiltinTypeValidate::null(null));
  }

  /**
   * 测试 null 校验 - 非空值应抛出异常
   *
   * @return void
   */
  public function testNullWithNonEmptyValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::null('value');
  }

  /**
   * 测试 null 校验 - 假值(0, '', false, [])不应被误判为 null
   *
   * @return void
   */
  public function testNullWithFalsyValuesThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::null(0);
  }

  /**
   * 测试 true 校验 - 严格 true 值返回 true
   *
   * @return void
   */
  public function testTrue(): void
  {
    static::assertTrue(BuiltinTypeValidate::true(true));
  }

  /**
   * 测试 true 校验 - 非严格 true 值应抛出异常
   *
   * @return void
   */
  public function testTrueWithNonStrictTrueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::true(1);
  }

  /**
   * 测试 true 校验 - 假值应抛出异常
   *
   * @return void
   */
  public function testTrueWithFalseValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::true(0);
  }

  /**
   * 测试 false 校验 - 严格 false 值返回 false
   *
   * @return void
   */
  public function testFalse(): void
  {
    static::assertFalse(BuiltinTypeValidate::false(false));
  }

  /**
   * 测试 false 校验 - 非严格 false 值应抛出异常
   *
   * @return void
   */
  public function testFalseWithNonStrictFalseThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::false(0);
  }

  /**
   * 测试 false 校验 - 真值应抛出异常
   *
   * @return void
   */
  public function testFalseWithTrueValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::false(1);
  }

  /**
   * 测试 mixed 校验 - 任意值原样返回
   *
   * @return void
   */
  public function testMixed(): void
  {
    static::assertEquals('string', BuiltinTypeValidate::mixed('string'));
    static::assertEquals(123, BuiltinTypeValidate::mixed(123));
    static::assertEquals([1, 2], BuiltinTypeValidate::mixed([1, 2]));
    static::assertNull(BuiltinTypeValidate::mixed(null));
  }

  /**
   * 测试 iterable 校验 - 数组
   *
   * @return void
   */
  public function testIterableWithArray(): void
  {
    $result = BuiltinTypeValidate::iterable([1, 2, 3]);
    static::assertEquals([1, 2, 3], $result);
  }

  /**
   * 测试 iterable 校验 - 非可迭代值应抛出异常
   *
   * @return void
   */
  public function testIterableWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::iterable('not-iterable');
  }

  /**
   * 测试 callable 校验
   *
   * @return void
   */
  public function testCallable(): void
  {
    $callback = fn() => 'test';
    $result = BuiltinTypeValidate::callable($callback);
    static::assertSame($callback, $result);
  }

  /**
   * 测试 callable 校验 - 非可调用值应抛出异常
   *
   * @return void
   */
  public function testCallableWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    BuiltinTypeValidate::callable('nonexistent_function');
  }

  /**
   * 测试 Closure 校验
   *
   * @return void
   */
  public function testClosure(): void
  {
    $closure = fn() => 'test';
    $result = BuiltinTypeValidate::Closure($closure);
    static::assertInstanceOf(Closure::class, $result);
  }

  /**
   * 测试 Closure 校验 - 非 Closure 应抛出异常
   *
   * @return void
   */
  public function testClosureWithInvalidValueThrowsException(): void
  {
    $this->expectException(ValidateException::class);
    // 普通函数不是 Closure
    BuiltinTypeValidate::Closure('strlen');
  }

  /**
   * 测试 isBuiltin - 字符串类型
   *
   * @return void
   */
  public function testIsBuiltinWithString(): void
  {
    static::assertTrue(BuiltinTypeValidate::isBuiltin('int'));
    static::assertTrue(BuiltinTypeValidate::isBuiltin('string'));
    static::assertTrue(BuiltinTypeValidate::isBuiltin('bool'));
    static::assertFalse(BuiltinTypeValidate::isBuiltin('NonExistentType'));
  }

  /**
   * 测试 isBuiltin - Type 枚举
   *
   * @return void
   */
  public function testIsBuiltinWithTypeEnum(): void
  {
    static::assertTrue(BuiltinTypeValidate::isBuiltin(Type::INT));
    static::assertTrue(BuiltinTypeValidate::isBuiltin(Type::STRING));
    // Closure 是内置类型
    static::assertTrue(BuiltinTypeValidate::isBuiltin(Type::CLOSURE));
  }
}
