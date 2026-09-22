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

use InvalidArgumentException;
use Override;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Collection\DataSet;
use Viswoole\Database\DbManager;
use Viswoole\Database\Model;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * 查询构建器 API 增强回归测试（P1 批次）
 *
 * 覆盖：columns() 接受 Raw 片段 / selectRaw() / master() 主库标记透传 /
 * value() 无行返回 false 语义锁定 / autoWritePk 两态行为 /
 * 非自增表 insertGetId 的 lastInsertId '0' 不覆盖写入主键
 */
class QueryApiTest extends TestCase
{
  use ProvidesSqliteStatement;

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
   * 构建捕获 build() 收到 Options 的通道替身
   */
  private function makeCaptureChannel(): FakeChannel
  {
    return new class ($this->makeSelectStatement()) extends FakeChannel {
      public ?Options $capturedOptions = null;

      #[Override]
      public function build(Options $options): Raw
      {
        $this->capturedOptions = $options;
        return new Raw('SELECT * FROM `users`', []);
      }
    };
  }

  /**
   * 用真实 SqlBuilder（经 PDOChannel::build）把捕获的 Options 构建为 SQL 字符串
   */
  private function buildSql(Options $options): string
  {
    return (new PDOChannel())->build($options)->toString();
  }

  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志
   */
  private function runQuietly(callable $fn): void
  {
    ob_start();
    try {
      $fn();
    } finally {
      ob_end_clean();
    }
  }

  /**
   * 创建返回空结果集的 SELECT 语句
   */
  private function makeEmptyStatement(): PDOStatement
  {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      self::markTestSkipped('当前环境未启用 pdo_sqlite 驱动，跳过该用例');
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT)');
    return $pdo->query('SELECT * FROM users WHERE id = -1');
  }

  // ---------- columns / selectRaw ----------

  /**
   * columns() 应原样追加 Raw 片段（不被 quote 加反引号）
   */
  public function testColumnsAcceptsRaw(): void
  {
    $capture = $this->makeCaptureChannel();
    // toRaw 仅生成 SQL 不执行（sqlite 结果集无 cnt 列）
    $this->runQuietly(fn () => $capture->table('users')
      ->columns(new Raw('COUNT(*) AS cnt'))
      ->toRaw()
      ->get());

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('COUNT(*) AS cnt', $sql);
    self::assertStringNotContainsString('`COUNT(*)`', $sql);
  }

  /**
   * selectRaw() 便捷方法应追加原生列并透传绑定参数
   */
  public function testSelectRawAppendsColumnWithBindings(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->selectRaw('price * ? AS discounted', [2])
      ->toRaw()
      ->get());

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('price * 2 AS discounted', $sql);
  }

  /**
   * columns() 字符串行为不变（别名解析回归锁定）
   */
  public function testColumnsStringBehaviorUnchanged(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->columns('id', 'name AS n')
      ->toRaw()
      ->get());

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('`name` AS `n`', $sql);
  }

  // ---------- master ----------

  /**
   * master() 应将主库标记透传到通道执行层
   */
  public function testMasterFlagPassedToChannel(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->master()
      ->where('id', 1)
      ->value('id'));

    self::assertTrue($capture->calls[0]['master']);
  }

  /**
   * 默认不强制主库（master 标记为 false，回归锁定）
   */
  public function testDefaultMasterFlagIsFalse(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->where('id', 1)
      ->value('id'));

    self::assertFalse($capture->calls[0]['master']);
  }

  // ---------- value 语义锁定 ----------

  /**
   * value() 查无行返回 false（既有语义锁定：大版本再评估改 null）
   */
  public function testValueReturnsFalseOnEmptyRow(): void
  {
    $fake = new class ($this->makeEmptyStatement()) extends FakeChannel {
      #[Override]
      public function build(Options $options): Raw
      {
        return new Raw('SELECT * FROM `users`', []);
      }
    };
    $this->makeManager($fake);
    $this->runQuietly(fn () => ExplicitPkModel::where('id', 1)->value('name'));

    $calls = $fake->calls;
    self::assertNotEmpty($calls);
    // 语义锁定：无行 value 返回 false（非 null）
    self::assertFalse(ExplicitPkModel::where('id', 1)->value('name'));
  }

  // ---------- autoWritePk ----------

  /**
   * 模型定义了 autoWritePk() 方法时应自动生成主键
   */
  public function testAutoWritePkWithMethodGeneratesPk(): void
  {
    // 非自增表 lastInsertId 恒返回 '0'，模拟雪花 ID 表真实行为
    $fake = new FakeChannel('0');
    $this->makeManager($fake);

    $ds = AutoWritePkModel::create(['name' => 'x']);
    $snowflakeId = 1941234567890124555;

    // 写入数据包含生成的主键（data 顺序：name 在前，注入的 id 在后）
    self::assertSame($snowflakeId, $fake->calls[0]['bindings'][1]);
    // DataSet 主键为生成值，且不被 lastInsertId '0' 覆盖
    self::assertSame($snowflakeId, $ds['id']);
  }

  /**
   * 开启 $autoWritePk 但模型未定义 autoWritePk() 方法时，
   * 应抛出明确的 InvalidArgumentException（修复前为魔术转发的晦涩 RuntimeException）
   */
  public function testAutoWritePkWithoutMethodThrowsClearError(): void
  {
    $fake = new FakeChannel(1);
    $this->makeManager($fake);

    try {
      AutoWritePkBrokenModel::create(['name' => 'x']);
      self::fail('未定义 autoWritePk() 方法未抛出异常');
    } catch (InvalidArgumentException $e) {
      self::assertStringContainsString('autoWritePk', $e->getMessage());
    }
  }
}

/**
 * 测试用模型：定义了 autoWritePk() 主键生成器
 */
class AutoWritePkModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';
  protected bool $autoWritePk = true;

  public function autoWritePk(): int
  {
    return 1941234567890124555;
  }
}

/**
 * 测试用模型：开启 $autoWritePk 但未定义生成方法（配置错误场景）
 */
class AutoWritePkBrokenModel extends Model
{
  protected string $table = 'users';
  protected string $pk = 'id';
  protected bool $autoWritePk = true;
}
