<?php

declare(strict_types=1);

/* 文件职责：回归框架枚举解析的值语义，独立于公告业务与外部存储。 */

namespace Viswoole\Tests\Core\Validate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate;

/** 稀疏整数枚举夹具，第三个位置不等于其回退值。 */
enum SparseStatus: int
{
  case DRAFT = 0;
  case PUBLISHED = 1;
  case OFFLINE = 4;
}

/** 字符串值与名称有意交叉，验证回退值优先且区分大小写。 */
enum StringDestination: string
{
  case INTERNAL = 'EXTERNAL';
  case EXTERNAL = 'https';
}

/** 纯枚举没有回退值，保留既有数字位置解析。 */
enum PureDestination
{
  case INTERNAL;
  case EXTERNAL;
}

/**
 * 枚举值解析回归
 */
final class EnumValidationTest extends TestCase
{
  /**
   * 提供整数枚举合法输入
   *
   * HTTP 数字字符串、实体枚举与既有名称输入应得到同一个领域值。
   *
   * @return iterable<string,array{mixed,SparseStatus}> 输入与预期枚举
   */
  public static function validStatuses(): iterable
  {
    yield '整数零' => [0, SparseStatus::DRAFT];
    yield '查询字符串零' => ['0', SparseStatus::DRAFT];
    yield '整数稀疏值' => [4, SparseStatus::OFFLINE];
    yield '查询字符串稀疏值' => ['4', SparseStatus::OFFLINE];
    yield '名称别名' => ['published', SparseStatus::PUBLISHED];
    yield '枚举实例' => [SparseStatus::PUBLISHED, SparseStatus::PUBLISHED];
  }

  /**
   * 按回退值解析整数枚举
   *
   * 不把数字解释为声明位置，保证稀疏枚举的 HTTP 值与数据库值一致。
   *
   * @param mixed $input 原始输入
   * @param SparseStatus $expected 预期领域状态
   * @return void 类型转换符合值契约
   */
  #[DataProvider('validStatuses')]
  public function testIntegerBackedValues(mixed $input, SparseStatus $expected): void
  {
    self::assertSame($expected, Validate::check($input, SparseStatus::class));
  }

  /**
   * 提供非法整数枚举输入
   *
   * 声明位置、浮点、布尔及溢出数字不能转换为合法回退值。
   *
   * @return iterable<string,array{mixed}> 非法输入集合
   */
  public static function invalidStatuses(): iterable
  {
    foreach ([2, '2', 3, '3', 1.0, true, false, '1.0', '1e0', '9223372036854775808'] as $index => $value) {
      yield (string)$index => [$value];
    }
  }

  /**
   * 拒绝非回退值输入
   *
   * 错误必须作为验证异常传播，不能按声明位置降级匹配。
   *
   * @param mixed $input 非法枚举输入
   * @return void 输入被拒绝
   * @throws ValidateException 输入不属于枚举
   */
  #[DataProvider('invalidStatuses')]
  public function testRejectsNonValues(mixed $input): void
  {
    $this->expectException(ValidateException::class);
    Validate::enum(SparseStatus::class, $input);
  }

  /**
   * 优先匹配字符串回退值
   *
   * 精确值优先于大小写不敏感的名称别名，纯枚举仍支持位置输入。
   *
   * @return void 字符串值与纯枚举行为一致
   */
  public function testStringValuesAndPureEnumIndexes(): void
  {
    self::assertSame(StringDestination::INTERNAL, Validate::enum(StringDestination::class, 'EXTERNAL'));
    self::assertSame(StringDestination::EXTERNAL, Validate::enum(StringDestination::class, 'https'));
    self::assertSame(StringDestination::INTERNAL, Validate::enum(StringDestination::class, 'internal'));
    self::assertSame(PureDestination::EXTERNAL, Validate::enum(PureDestination::class, 1));
  }

  /**
   * 拒绝字符串枚举的数字位置
   *
   * backed enum 统一采用回退值语义，不接受不存在的数字回退值。
   *
   * @return void 数字位置被拒绝
   * @throws ValidateException 数字不是字符串回退值
   */
  public function testStringBackedEnumRejectsIndexes(): void
  {
    $this->expectException(ValidateException::class);
    Validate::enum(StringDestination::class, 0);
  }
}
