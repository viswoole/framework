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
   * 通配获取器兜底：未命中具名获取器的字段按字段名模式转换，其余透传
   */
  public function testWildcardGetterAppliedToFallbackFields(): void
  {
    $query = (new WildcardIdModel())->query;

    self::assertSame('1941234567890123456', $query->withGetAttr('user_id', 1941234567890123456), '*_id 字段应经通配获取器字符串化');
    self::assertSame('plain', $query->withGetAttr('name', 'plain'), '非 ID 字段应原值返回');
  }

  /**
   * 具名获取器优先于通配获取器（同字段两者并存时具名生效）
   */
  public function testNamedGetterTakesPrecedenceOverWildcard(): void
  {
    $query = (new WildcardIdModel())->query;

    self::assertSame('NAMED:1', $query->withGetAttr('order_id', 1), 'order_id 有具名获取器应优先于通配');
    self::assertSame('2', $query->withGetAttr('user_id', 2), '无具名获取器的字段仍走通配');
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
   * 非 public 的获取器/修改器视为未定义：原值透传而非触发 __call 转发链（防无限递归回归）
   */
  public function testNonPublicAttrTreatedAsUndefined(): void
  {
    $query = (new ProtectedAttrModel())->query;

    self::assertSame('alice', $query->withGetAttr('user_name', 'alice'), 'protected 具名获取器视为未定义，原值返回');
    self::assertSame(123, $query->withGetAttr('user_id', 123), 'protected 通配获取器视为未定义，原值返回');
    self::assertSame('x', $query->withSetAttr('user_id', 'x'), 'protected 通配修改器视为未定义，原值返回');
  }

  /**
   * 通配修改器兜底：写入路径将字符串 *_id 归一为整型，非 ID 字段不转换
   */
  public function testWildcardSetterAppliedOnWrite(): void
  {
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    WildcardIdModel::create(['user_id' => '194123456789', 'name' => 'a']);
    $bindings = $fake->calls[0]['bindings'];

    self::assertSame(194123456789, $bindings[0], '通配 setAttr 应将字符串 *_id 归一为整型');
    self::assertSame('a', $bindings[1], '非 ID 字段不应被通配修改器转换');
  }
}

/**
 * 测试用模型：定义通配获取器/修改器（模拟雪花 ID 基类按 *_id 后缀转换），
 * order_id 另定义具名获取器用于验证优先级
 */
class WildcardIdModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  /**
   * 通配获取器：*_id 后缀字段字符串化（模拟雪花 ID 防精度丢失）
   */
  public static function getAttr(string $field, mixed $value): mixed
  {
    if ($value === null || !str_ends_with($field, '_id')) {
      return $value;
    }
    return (string)$value;
  }

  /**
   * 通配修改器：*_id 后缀字段归一为整型（模拟字符串 ID 回传落库）
   */
  public static function setAttr(string $field, mixed $value): mixed
  {
    if ($value === null || !str_ends_with($field, '_id')) {
      return $value;
    }
    return (int)$value;
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

/**
 * 测试用模型：具名与通配获取器/修改器均声明为 protected（模拟可见性误用场景，
 * 历史上会触发 Model::__call ↔ Query::__call 无限递归直至内存耗尽）
 */
class ProtectedAttrModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  /**
   * protected 具名获取器：应视为未定义
   */
  protected function getUserNameAttr(mixed $value): string
  {
    return 'SHOULD_NOT_REACH';
  }

  /**
   * protected 通配获取器：应视为未定义
   */
  protected function getAttr(string $field, mixed $value): mixed
  {
    return 'SHOULD_NOT_REACH';
  }

  /**
   * protected 通配修改器：应视为未定义
   */
  protected function setAttr(string $field, mixed $value): mixed
  {
    return 'SHOULD_NOT_REACH';
  }
}
