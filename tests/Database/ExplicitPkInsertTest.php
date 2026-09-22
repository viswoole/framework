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

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\Collection;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;

/**
 * create/append 显式主键回填保护回归测试
 *
 * 修复前缺陷：Model::create() 与 Collection::append($value, true) 无条件走
 * insertGetId 并用 lastInsertId 回填主键——雪花 ID 表（应用侧显式传主键、
 * 非自增）的 lastInsertId 返回 '0'，会覆盖手动传入的主键。
 *
 * 修复后：主键已显式传入（非 null/''/0）时直接 insert 并以传入值为准；
 * 未传或传 0（自增列语义，由 MySQL 重新生成）时保持 insertGetId 回填原行为。
 */
class ExplicitPkInsertTest extends TestCase
{
  /**
   * 构建带 FakeChannel 默认通道的应用环境
   */
  private function makeManager(FakeChannel $fake): DbManager
  {
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $fake);
    $manager->setDebug(false);
    return $manager;
  }

  /**
   * create 显式传雪花主键时应走 insert 并保留传入值（修复前被 lastInsertId '0' 覆盖）
   */
  public function testCreateWithExplicitPkKeepsId(): void
  {
    // insert 路径 execute 返回受影响行数（int）
    $fake = new FakeChannel(0);
    $this->makeManager($fake);

    $snowflakeId = 1941234567890124555;
    $ds = ExplicitPkModel::create(['id' => $snowflakeId, 'name' => 'x']);

    // 显式主键应走 insert 路径（不请求 lastInsertId 回填）
    self::assertFalse($fake->calls[0]['getId'], '显式主键不应走 insertGetId 回填路径');
    // 写入数据包含主键列
    self::assertSame($snowflakeId, $fake->calls[0]['bindings'][0]);
    // DataSet 主键以传入值为准（修复前为 '0'）
    self::assertSame($snowflakeId, $ds['id']);
  }

  /**
   * create 未传主键时保持 insertGetId 回填原行为
   */
  public function testCreateWithoutPkFillsFromInsertGetId(): void
  {
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    $ds = ExplicitPkModel::create(['name' => 'x']);

    self::assertSame('id', $fake->calls[0]['getId']);
    self::assertSame('7', $ds['id']);
  }

  /**
   * create 传主键 0（自增列语义，MySQL 重新生成）时保持 insertGetId 回填
   */
  public function testCreateWithZeroPkStillAutoIncrement(): void
  {
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    $ds = ExplicitPkModel::create(['id' => 0, 'name' => 'x']);

    self::assertSame('id', $fake->calls[0]['getId']);
    self::assertSame('7', $ds['id']);
  }

  /**
   * create 传字符串 '0'（表单空值常见形态）同样视为未显式传入，
   * 保持 insertGetId 回填真实自增值（DataSet 与库内数据一致）
   */
  public function testCreateWithStringZeroPkStillAutoIncrement(): void
  {
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    $ds = ExplicitPkModel::create(['id' => '0', 'name' => 'x']);

    self::assertSame('id', $fake->calls[0]['getId']);
    self::assertSame('7', $ds['id']);
  }

  /**
   * append($value, true) 显式传主键时应走 insert 并保留传入值
   */
  public function testAppendWithExplicitPkKeepsId(): void
  {
    $fake = new FakeChannel(0);
    $this->makeManager($fake);

    $snowflakeId = 1941234567890124555;
    // ⚠️ Model::query() 是真实存在的实例方法，静态调用直接 PHP Error（不走
    // __callStatic）——须先实例化再取公开只读 $query 属性
    $collection = new Collection((new ExplicitPkModel())->query, []);
    $collection->append(['id' => $snowflakeId, 'name' => 'x'], true);

    self::assertFalse($fake->calls[0]['getId'], '显式主键不应走 insertGetId 回填路径');
    $row = $collection->first();
    self::assertInstanceOf(DataSet::class, $row);
    self::assertSame($snowflakeId, $row['id']);
  }

  /**
   * append 未传主键时保持 insertGetId 回填原行为
   */
  public function testAppendWithoutPkFillsFromInsertGetId(): void
  {
    $fake = new FakeChannel('9');
    $this->makeManager($fake);

    $collection = new Collection((new ExplicitPkModel())->query, []);
    $collection->append(['name' => 'x'], true);

    self::assertSame('id', $fake->calls[0]['getId']);
    $row = $collection->first();
    self::assertInstanceOf(DataSet::class, $row);
    self::assertSame('9', $row['id']);
  }

  /**
   * append 传字符串 '0' 主键同样视为未显式传入，保持 insertGetId 回填
   */
  public function testAppendWithStringZeroPkStillAutoIncrement(): void
  {
    $fake = new FakeChannel('9');
    $this->makeManager($fake);

    $collection = new Collection((new ExplicitPkModel())->query, []);
    $collection->append(['id' => '0', 'name' => 'x'], true);

    self::assertSame('id', $fake->calls[0]['getId']);
    $row = $collection->first();
    self::assertInstanceOf(DataSet::class, $row);
    self::assertSame('9', $row['id']);
  }
}

/**
 * 测试用模型：雪花主键表（无修改器、无时间戳自动写入）
 */
class ExplicitPkModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';
}
