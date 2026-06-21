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

namespace Viswoole\Tests\Common;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\Common\Arr;

/**
 * 数组辅助类测试
 */
class ArrTest extends TestCase
{
  /**
   * 测试索引数组判断 - 标准索引数组
   *
   * @return void
   */
  public function testIsIndexArrayWithStandardIndexArray(): void
  {
    static::assertTrue(Arr::isIndexArray([1, 2, 3]));
    static::assertTrue(Arr::isIndexArray(['a', 'b', 'c']));
  }

  /**
   * 测试索引数组判断 - 关联数组应返回 false
   *
   * @return void
   */
  public function testIsIndexArrayWithAssociativeArray(): void
  {
    static::assertFalse(Arr::isIndexArray(['name' => 'test', 'age' => 18]));
  }

  /**
   * 测试索引数组判断 - 空数组默认返回 false
   *
   * @return void
   */
  public function testIsIndexArrayWithEmptyArrayDefault(): void
  {
    static::assertFalse(Arr::isIndexArray([]));
  }

  /**
   * 测试索引数组判断 - 空数组允许模式返回 true
   *
   * @return void
   */
  public function testIsIndexArrayWithEmptyArrayAllowEmpty(): void
  {
    static::assertTrue(Arr::isIndexArray([], true));
  }

  /**
   * 测试索引数组判断 - 非连续键应返回 false
   *
   * @return void
   */
  public function testIsIndexArrayWithNonConsecutiveKeys(): void
  {
    static::assertFalse(Arr::isIndexArray([0 => 'a', 2 => 'b']));
  }

  /**
   * 测试关联数组判断 - 标准关联数组
   *
   * @return void
   */
  public function testIsAssociativeArrayWithAssociativeArray(): void
  {
    static::assertTrue(Arr::isAssociativeArray(['name' => 'test', 'age' => 18]));
  }

  /**
   * 测试关联数组判断 - 索引数组应返回 false
   *
   * @return void
   */
  public function testIsAssociativeArrayWithIndexArray(): void
  {
    static::assertFalse(Arr::isAssociativeArray([1, 2, 3]));
  }

  /**
   * 测试关联数组判断 - 空数组默认返回 true
   *
   * @return void
   */
  public function testIsAssociativeArrayWithEmptyArrayDefault(): void
  {
    // 空数组默认 allowEmpty=false，isIndexArray 返回 false，所以 isAssociativeArray 返回 true
    static::assertTrue(Arr::isAssociativeArray([]));
  }

  /**
   * 测试从数组中弹出指定键的值 - 键存在
   *
   * @return void
   */
  public function testArrayPopValueWithExistingKey(): void
  {
    $array = ['name' => 'test', 'age' => 18];
    $value = Arr::arrayPopValue($array, 'name');
    static::assertEquals('test', $value);
    // 验证原数组已被修改
    static::assertArrayNotHasKey('name', $array);
    static::assertEquals(['age' => 18], $array);
  }

  /**
   * 测试从数组中弹出指定键的值 - 键不存在返回默认值
   *
   * @return void
   */
  public function testArrayPopValueWithNonExistingKey(): void
  {
    $array = ['name' => 'test'];
    $value = Arr::arrayPopValue($array, 'age', 20);
    static::assertEquals(20, $value);
    // 原数组不应被修改
    static::assertEquals(['name' => 'test'], $array);
  }

  /**
   * 测试从数组中弹出指定键的值 - 键不存在且未传默认值返回 null
   *
   * @return void
   */
  public function testArrayPopValueWithNonExistingKeyAndNoDefault(): void
  {
    $array = ['name' => 'test'];
    $value = Arr::arrayPopValue($array, 'age');
    static::assertNull($value);
  }

  /**
   * 测试从数组中弹出指定键的值 - 整数键
   *
   * @return void
   */
  public function testArrayPopValueWithIntegerKey(): void
  {
    $array = [10 => 'a', 20 => 'b'];
    $value = Arr::arrayPopValue($array, 10);
    static::assertEquals('a', $value);
    static::assertArrayNotHasKey(10, $array);
  }
}
