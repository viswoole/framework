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
use Viswoole\Core\App;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate;
use Viswoole\Core\Validate\Rules\Between;
use Viswoole\Core\Validate\Rules\Min;
use Viswoole\Core\Validate\Type;

/**
 * 测试用枚举
 */
enum TestStatus: string
{
  case Active = 'active';
  case Inactive = 'inactive';
  case Pending = 'pending';
}

/**
 * 验证器测试
 */
class ValidateTest extends TestCase
{
  /**
   * 测试前初始化 App 容器（Validate::class 方法依赖容器）
   *
   * @return void
   */
  protected function setUp(): void
  {
    App::factory();
  }

  /**
   * 测试 check 方法 - 验证内置类型 int
   *
   * @return void
   */
  public function testCheckWithBuiltinTypeInt(): void
  {
    $result = Validate::check('123', 'int');
    static::assertEquals(123, $result);
  }

  /**
   * 测试 check 方法 - 验证内置类型 string
   *
   * @return void
   */
  public function testCheckWithBuiltinTypeString(): void
  {
    $result = Validate::check('hello', 'string');
    static::assertEquals('hello', $result);
  }

  /**
   * 测试 check 方法 - 验证内置类型失败
   *
   * @return void
   */
  public function testCheckWithBuiltinTypeFailure(): void
  {
    $this->expectException(ValidateException::class);
    Validate::check('not-a-number', 'int');
  }

  /**
   * 测试 check 方法 - 使用 Type 枚举
   *
   * @return void
   */
  public function testCheckWithTypeEnum(): void
  {
    $result = Validate::check('hello', Type::STRING);
    static::assertEquals('hello', $result);
  }

  /**
   * 测试 check 方法 - 联合类型（数组形式）
   *
   * @return void
   */
  public function testCheckWithUnionTypeArray(): void
  {
    // int|string 联合类型，传入字符串数字应匹配 int
    $result = Validate::check('123', ['int', 'string']);
    static::assertEquals(123, $result);
    // 传入非数字字符串应匹配 string
    $result = Validate::check('hello', ['int', 'string']);
    static::assertEquals('hello', $result);
  }

  /**
   * 测试 check 方法 - 联合类型（管道符形式）
   *
   * @return void
   */
  public function testCheckWithUnionTypePipe(): void
  {
    $result = Validate::check('hello', 'int|string');
    static::assertEquals('hello', $result);
  }

  /**
   * 测试 check 方法 - 联合类型全部不匹配应失败
   *
   * @return void
   */
  public function testCheckWithUnionTypeAllFailure(): void
  {
    $this->expectException(ValidateException::class);
    // 数组类型无法匹配 int 或 string
    Validate::check([1, 2], 'int|string');
  }

  /**
   * 测试 check 方法 - 验证枚举（字符串）
   *
   * @return void
   */
  public function testCheckWithEnumString(): void
  {
    $result = Validate::check('active', TestStatus::class);
    static::assertEquals(TestStatus::Active, $result);
  }

  /**
   * 测试 check 方法 - 验证枚举（大小写不敏感）
   *
   * @return void
   */
  public function testCheckWithEnumCaseInsensitive(): void
  {
    $result = Validate::check('ACTIVE', TestStatus::class);
    static::assertEquals(TestStatus::Active, $result);
  }

  /**
   * 测试 check 方法 - 验证枚举（数字索引）
   *
   * @return void
   */
  public function testCheckWithEnumIndex(): void
  {
    $result = Validate::check(0, TestStatus::class);
    static::assertEquals(TestStatus::Active, $result);
  }

  /**
   * 测试 check 方法 - 验证枚举失败
   *
   * @return void
   */
  public function testCheckWithEnumFailure(): void
  {
    $this->expectException(ValidateException::class);
    Validate::check('nonexistent', TestStatus::class);
  }

  /**
   * 测试 check 方法 - 验证枚举实例直接返回
   *
   * @return void
   */
  public function testCheckWithEnumInstance(): void
  {
    $result = Validate::check(TestStatus::Active, TestStatus::class);
    static::assertSame(TestStatus::Active, $result);
  }

  /**
   * 测试 checkRules 方法 - 单个规则
   *
   * @return void
   */
  public function testCheckRulesWithSingleRule(): void
  {
    $rule = new Min(10);
    $result = Validate::checkRules($rule, 20);
    static::assertEquals(20, $result);
  }

  /**
   * 测试 checkRules 方法 - 规则数组
   *
   * @return void
   */
  public function testCheckRulesWithMultipleRules(): void
  {
    $rules = [new Min(1), new Between(1, 100)];
    $result = Validate::checkRules($rules, 50);
    static::assertEquals(50, $result);
  }

  /**
   * 测试 checkRules 方法 - 空规则数组原样返回
   *
   * @return void
   */
  public function testCheckRulesWithEmptyRules(): void
  {
    $result = Validate::checkRules([], 'value');
    static::assertEquals('value', $result);
  }

  /**
   * 测试 checkRules 方法 - 验证失败抛出异常
   *
   * @return void
   */
  public function testCheckRulesFailure(): void
  {
    $this->expectException(ValidateException::class);
    $rule = new Min(10);
    Validate::checkRules($rule, 5);
  }

  /**
   * 测试 intersection 方法 - 交集类型验证
   *
   * @return void
   */
  public function testIntersection(): void
  {
    // 创建一个同时实现多个接口的测试类
    $value = new class implements \Countable, \ArrayAccess {
      public function count(): int
      {
        return 0;
      }

      public function offsetExists(mixed $offset): bool
      {
        return false;
      }

      public function offsetGet(mixed $offset): mixed
      {
        return null;
      }

      public function offsetSet(mixed $offset, mixed $value): void
      {
      }

      public function offsetUnset(mixed $offset): void
      {
      }
    };
    $result = Validate::intersection('Countable&ArrayAccess', $value);
    static::assertSame($value, $result);
  }

  /**
   * 测试 intersection 方法 - 不满足交集应失败
   *
   * @return void
   */
  public function testIntersectionFailure(): void
  {
    $this->expectException(ValidateException::class);
    Validate::intersection('Countable', 'not-a-countable');
  }

  /**
   * 测试 enum 方法 - 直接调用
   *
   * @return void
   */
  public function testEnumMethod(): void
  {
    $result = Validate::enum(TestStatus::class, 'pending');
    static::assertEquals(TestStatus::Pending, $result);
  }
}
