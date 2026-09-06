<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;

/**
 * 通配获取器/通配修改器（getAttr/setAttr）测试
 *
 * 验证兜底机制：未命中具名 (get|set){Field}Attr 的字段回退通配方法
 * （$field 为蛇形原名字段名，便于按后缀模式匹配），具名方法优先于通配；
 * 未定义通配方法的模型行为不变（原值透传）。
 *
 * 测试模型的转换策略为推荐范式：
 * - getAttr 按值兜底：仅超过 JS 安全整数（2^53-1）的整型字符串化；
 * - setAttr 按字段名+值兜底：*_id 后缀且为纯数字字符串（≤19 位防 int 溢出
 *   饱和）才归一整型——防御 varchar _id 列（设备指纹/认证凭据等非数字值）
 *   被误转成 0 写坏数据。
 */
class WildcardAttrTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建带 FakeChannel 默认通道的应用环境
   *
   * @param FakeChannel $fake 通道测试替身
   * @return DbManager 注入替身通道的管理器
   */
  private function makeManager(FakeChannel $fake): DbManager
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $fake);
    $manager->setDebug(false);
    return $manager;
  }

  /**
   * 通配获取器按值兜底：超安全范围的整型字符串化，其余透传
   */
  public function testWildcardGetterAppliedToFallbackFields(): void
  {
    $query = (new WildcardIdModel())->query;

    self::assertSame('1941234567890123456', $query->withGetAttr('user_id', 1941234567890123456), '超安全范围整型应字符串化');
    self::assertSame(80001, $query->withGetAttr('uid', 80001), '安全范围内整型应保持数字');
    self::assertSame('plain', $query->withGetAttr('name', 'plain'), '字符串值应原样透传');
  }

  /**
   * 具名获取器优先于通配获取器（同字段两者并存时具名生效）
   */
  public function testNamedGetterTakesPrecedenceOverWildcard(): void
  {
    $query = (new WildcardIdModel())->query;

    self::assertSame('NAMED:1', $query->withGetAttr('order_id', 1), 'order_id 有具名获取器应优先于通配');
    self::assertSame('1941234567890123456', $query->withGetAttr('user_id', 1941234567890123456), '无具名获取器的字段仍走通配');
  }

  /**
   * 未定义通配方法的模型行为不变（读取/写入均原值透传）
   */
  public function testModelWithoutWildcardUnaffected(): void
  {
    $query = (new PlainModel())->query;

    self::assertSame(123, $query->withGetAttr('user_id', 123));
    self::assertSame('x', $query->withSetAttr('user_id', 'x'));
  }

  /**
   * 通配修改器兜底：写入路径将合法长度的纯数字字符串归一为整型
   */
  public function testWildcardSetterAppliedOnWrite(): void
  {
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    WildcardIdModel::create(['user_id' => '194123456789', 'name' => 'a']);
    $bindings = $fake->calls[0]['bindings'];

    self::assertSame(194123456789, $bindings[0], '通配 setAttr 应将纯数字字符串归一为整型');
    self::assertSame('a', $bindings[1], '非 ID 字段不应被通配修改器转换');
  }

  /**
   * varchar _id 列的非数字值（设备指纹/认证凭据等）不应被 (int) 误转成 0
   */
  public function testWildcardSetterSkipsNonNumericIdValues(): void
  {
    $query = (new WildcardIdModel())->query;

    self::assertSame('fp-abc-123', $query->withSetAttr('device_id', 'fp-abc-123'), '非数字字符串应原样透传');
    self::assertSame('certify-archive-1', $query->withSetAttr('certify_id', 'certify-archive-1'));
  }

  /**
   * 超长数字字符串（≥20 位，超出 int 表示范围）不应被 (int) 溢出饱和
   */
  public function testWildcardSetterSkipsOverlongNumericStrings(): void
  {
    $query = (new WildcardIdModel())->query;

    self::assertSame(str_repeat('9', 20), $query->withSetAttr('user_id', str_repeat('9', 20)), '超长数字串应原样透传防溢出饱和');
  }
}

/**
 * 测试用模型：定义通配获取器/修改器（推荐范式——值感知 + 长度防御），
 * order_id 另定义具名获取器用于验证优先级
 */
class WildcardIdModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  /** JS Number 最大安全整数（2^53 - 1） */
  private const int JS_MAX_SAFE_INT = 9007199254740991;

  /**
   * 通配获取器：超过 JS 安全整数（2^53-1）的整型字符串化（防 JS 精度丢失）
   */
  public static function getAttr(string $field, mixed $value): mixed
  {
    if (is_int($value) && ($value > self::JS_MAX_SAFE_INT || $value < -self::JS_MAX_SAFE_INT)) {
      return (string)$value;
    }
    return $value;
  }

  /**
   * 通配修改器：*_id 后缀且为纯数字字符串（≤19 位）归一为整型，
   * 其余（非数字值/超长数字串）原样透传
   */
  public static function setAttr(string $field, mixed $value): mixed
  {
    if (str_ends_with($field, '_id')
      && is_string($value) && ctype_digit($value) && strlen($value) <= 19) {
      return (int)$value;
    }
    return $value;
  }

  /**
   * 具名获取器：应优先于通配获取器
   */
  public function getOrderIdAttr(mixed $value): string
  {
    return 'NAMED:' . $value;
  }
}

/**
 * 测试用模型：无任何获取器/修改器（验证未定义通配时行为不变）
 */
class PlainModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';
}
