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
use Viswoole\Core\Common\Str;

/**
 * 字符串辅助类测试
 */
class StrTest extends TestCase
{
  /**
   * 测试骆驼形转蛇形 - 单词首字母大写
   *
   * @return void
   */
  public function testCamelCaseToSnakeCaseWithPascalCase(): void
  {
    static::assertEquals('user_name', Str::camelCaseToSnakeCase('UserName'));
  }

  /**
   * 测试骆驼形转蛇形 - 小驼峰
   *
   * @return void
   */
  public function testCamelCaseToSnakeCaseWithCamelCase(): void
  {
    static::assertEquals('user_name', Str::camelCaseToSnakeCase('userName'));
  }

  /**
   * 测试骆驼形转蛇形 - 多个单词
   *
   * @return void
   */
  public function testCamelCaseToSnakeCaseWithMultipleWords(): void
  {
    static::assertEquals('get_user_info_by_id', Str::camelCaseToSnakeCase('getUserInfoById'));
  }

  /**
   * 测试骆驼形转蛇形 - 单个单词
   *
   * @return void
   */
  public function testCamelCaseToSnakeCaseWithSingleWord(): void
  {
    static::assertEquals('user', Str::camelCaseToSnakeCase('user'));
    static::assertEquals('user', Str::camelCaseToSnakeCase('User'));
  }

  /**
   * 测试蛇形转骆驼形 - 标准蛇形
   *
   * @return void
   */
  public function testSnakeCaseToCamelCaseWithSnakeCase(): void
  {
    static::assertEquals('userName', Str::snakeCaseToCamelCase('user_name'));
  }

  /**
   * 测试蛇形转骆驼形 - 多个单词
   *
   * @return void
   */
  public function testSnakeCaseToCamelCaseWithMultipleWords(): void
  {
    static::assertEquals('getUserInfoById', Str::snakeCaseToCamelCase('get_user_info_by_id'));
  }

  /**
   * 测试蛇形转骆驼形 - 单个单词
   *
   * @return void
   */
  public function testSnakeCaseToCamelCaseWithSingleWord(): void
  {
    static::assertEquals('user', Str::snakeCaseToCamelCase('user'));
  }

  /**
   * 测试蛇形与骆驼形互转 - 往返转换
   *
   * @return void
   */
  public function testRoundTripConversion(): void
  {
    $original = 'getUserInfoById';
    $snake = Str::camelCaseToSnakeCase($original);
    $camel = Str::snakeCaseToCamelCase($snake);
    static::assertEquals($original, $camel);
  }
}
