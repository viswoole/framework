<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;

/**
 * 模型修改器（set{Field}Attr）测试
 *
 * 验证写入路径（insert/insertGetId/update/create）自动应用修改器：
 * 值转换生效、无修改器字段原样透传、批量写入逐行应用、
 * 框架注入字段（时间戳/主键）不经过用户修改器。
 */
class ModelMutatorTest extends TestCase
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
    // 关闭 SQL 调试控制台输出，避免污染 PHPUnit 输出被标记为 risky
    $manager->setDebug(false);
    return $manager;
  }

  /**
   * create() 写入时修改器生效：password 哈希、user_name 大写，无修改器字段原样
   */
  public function testCreateAppliesMutators(): void
  {
    // insertGetId 路径 execute 直接返回字符串自增ID，无需 PDOStatement 替身
    $fake = new FakeChannel('7');
    $this->makeManager($fake);

    $data = UserMutatorModel::create([
      'user_name' => 'alice',
      'password' => 'secret',
      'age' => 20,
    ]);
    $bindings = $fake->calls[0]['bindings'];

    self::assertSame('ALICE', $bindings[0], 'setUserNameAttr 应将值转为大写');
    self::assertSame(sha1('secret'), $bindings[1], 'setPasswordAttr 应做哈希转换');
    self::assertSame(20, $bindings[2], '无修改器字段应原样透传');
    // 返回的 DataSet 保留转换后的值（与实际写入一致）
    self::assertSame('ALICE', $data['user_name']);
  }

  /**
   * update() 写入时修改器生效
   */
  public function testUpdateAppliesMutators(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    UserMutatorModel::where('id', 1)->update([
      'user_name' => 'bob',
      'password' => 'pass',
    ]);
    $bindings = $fake->calls[0]['bindings'];

    self::assertSame('BOB', $bindings[0], 'update 路径修改器应生效');
    self::assertSame(sha1('pass'), $bindings[1]);
  }

  /**
   * 批量 insert 逐行应用修改器
   */
  public function testBatchInsertAppliesMutatorsPerRow(): void
  {
    $fake = new FakeChannel(2);
    $this->makeManager($fake);

    UserMutatorModel::insert([
      ['user_name' => 'alice', 'password' => 'a'],
      ['user_name' => 'bob', 'password' => 'b'],
    ]);
    $bindings = $fake->calls[0]['bindings'];

    self::assertSame('ALICE', $bindings[0]);
    self::assertSame(sha1('a'), $bindings[1]);
    self::assertSame('BOB', $bindings[2], '第二行同样应用修改器');
    self::assertSame(sha1('b'), $bindings[3]);
  }

  /**
   * 框架自动注入的时间戳不经过用户修改器（避免框架字段被业务转换污染）
   */
  public function testAutoTimestampSkipsMutators(): void
  {
    $fake = new FakeChannel('1');
    $this->makeManager($fake);

    TimestampUserModel::create(['user_name' => 'alice']);
    $bindings = $fake->calls[0]['bindings'];

    // 用户数据 + 注入时间戳 = 2 个绑定参数，证明 create_time 已注入
    self::assertCount(2, $bindings, '时间戳字段应正常注入');
    // TimestampUserModel 未定义 user_name 修改器，原值透传
    self::assertSame('alice', $bindings[0]);
    // 用户数据在前、框架注入在后：bindings[1] 为注入的 create_time，
    // 若误入用户修改器将被改为 "TIME:..." 前缀，此断言可检出
    $createTime = (string)$bindings[1];
    self::assertDoesNotMatchRegularExpression('/^TIME:/', $createTime, '框架注入字段不应经过用户修改器');
    self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createTime);
  }

  /**
   * withSetAttr 直接调用：命中修改器返回转换值，未命中返回原值
   */
  public function testWithSetAttrDirectCall(): void
  {
    $fake = new FakeChannel(0);
    $this->makeManager($fake);
    $query = (new UserMutatorModel())->query;

    self::assertSame('UPPER', $query->withSetAttr('user_name', 'upper'));
    self::assertSame('raw', $query->withSetAttr('no_mutator_field', 'raw'), '无修改器字段应原值返回');
  }
}

/**
 * 测试用用户模型：定义 user_name/password 修改器
 */
class UserMutatorModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';

  /**
   * user_name 修改器：转大写（模拟规范化场景）
   */
  public function setUserNameAttr(mixed $value): string
  {
    return strtoupper((string)$value);
  }

  /**
   * password 修改器：哈希转换（模拟密码加密场景）
   */
  public function setPasswordAttr(mixed $value): string
  {
    return sha1((string)$value);
  }
}

/**
 * 测试用开启时间戳的模型：验证框架注入字段与修改器的隔离
 */
class TimestampUserModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';
  protected int $autoWriteTimestamp = 1;

  /**
   * create_time 修改器：若框架注入字段误入用户修改器，此断言会被检测到（值被改为 TIME 前缀）
   */
  public function setCreateTimeAttr(mixed $value): string
  {
    return 'TIME:' . $value;
  }
}
