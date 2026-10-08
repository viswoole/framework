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
use Viswoole\Database\DbManager;
use Viswoole\Database\Facade\Db;
use Viswoole\Database\Model;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * increment/decrement 与 exists/doesntExist 回归测试
 *
 * 覆盖：自增/自减 SQL 形态与绑定参数顺序（SET 在前 WHERE 在后）/
 * extra 附加字段混排 / 非法列名拦截 / exists SELECT 1 ... LIMIT 1
 * 生成与真假判定 / toRaw 标记被 exists 忽略
 */
class IncrementExistsTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建捕获 build() 收到 Options 的通道替身
   */
  private function makeCaptureChannel(): FakeChannel
  {
    return new class ($this->makeSelectStatement()) extends FakeChannel {
      /** @var Options|null 最近一次 build 收到的查询选项 */
      public ?Options $capturedOptions = null;

      #[Override]
      public function build(Options $options): Raw
      {
        $this->capturedOptions = $options;
        return new Raw('', []);
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
   * 创建返回空结果集的 SELECT 语句（无 pdo_sqlite 驱动时跳过用例）
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

  // ---------- increment / decrement ----------

  /**
   * 自增默认步长：生成 col = col + ?，绑定顺序为增量在前、where 值在后
   */
  public function testIncrementBuildsUpdateSql(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->where('id', 1)
      ->increment('login_count'));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      'UPDATE `user` SET `login_count` = login_count + 1 WHERE `id` = 1',
      $sql
    );
    $raw = (new PDOChannel())->build($capture->capturedOptions);
    self::assertSame([1, 1], $raw->bindings);
  }

  /**
   * 自减指定步长：生成 col = col - ?
   */
  public function testDecrementBuildsUpdateSql(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('stock')
      ->where('sku', 'A001')
      ->decrement('qty', 3));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      "UPDATE `stock` SET `qty` = qty - 3 WHERE `sku` = 'A001'",
      $sql
    );
    $raw = (new PDOChannel())->build($capture->capturedOptions);
    self::assertSame([3, 'A001'], $raw->bindings);
  }

  /**
   * extra 附加字段：Raw 表达式在前，标量字段随后按声明顺序展开
   */
  public function testIncrementWithExtraData(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->where('id', 1)
      ->increment('score', 5, ['level' => 2]));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      'UPDATE `user` SET `score` = score + 5, `level` = 2 WHERE `id` = 1',
      $sql
    );
    $raw = (new PDOChannel())->build($capture->capturedOptions);
    self::assertSame([5, 2, 1], $raw->bindings);
  }

  /**
   * 非法列名（含空白/特殊字符）在写入前拦截
   */
  public function testIncrementRejectsInvalidColumn(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('无效的自增字段');
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->where('id', 1)
      ->increment('score; DROP TABLE user'));
  }

  // ---------- exists / doesntExist ----------

  /**
   * exists 生成 SELECT 1 ... LIMIT 1，命中数据返回 true
   */
  public function testExistsReturnsTrueWhenRowsFound(): void
  {
    $capture = $this->makeCaptureChannel();
    $result = null;
    $this->runQuietly(function () use ($capture, &$result) {
      $result = $capture->table('user')->where('id', 1)->exists();
    });

    self::assertTrue($result);
    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame('SELECT 1 FROM `user` WHERE `id` = 1 LIMIT 1', $sql);
  }

  /**
   * 空结果时 exists 返回 false、doesntExist 返回 true
   */
  public function testExistsReturnsFalseWhenEmpty(): void
  {
    $fake = new class ($this->makeEmptyStatement()) extends FakeChannel {
      #[Override]
      public function build(Options $options): Raw
      {
        return new Raw('SELECT 1 FROM `user` LIMIT 1', []);
      }
    };
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $fake);
    $manager->setDebug(false);

    $result = null;
    $this->runQuietly(function () use (&$result) {
      $result = Db::table('user')->exists();
    });
    self::assertFalse($result);

    $result = null;
    $this->runQuietly(function () use (&$result) {
      $result = Db::table('user')->doesntExist();
    });
    self::assertTrue($result);
  }

  /**
   * 调用方设置的 toRaw 标记被 exists() 忽略，仍按真实执行判定
   */
  public function testExistsIgnoresToRawFlag(): void
  {
    $capture = $this->makeCaptureChannel();
    $result = null;
    $this->runQuietly(function () use ($capture, &$result) {
      $result = $capture->table('user')->toRaw()->exists();
    });
    self::assertTrue($result);
    self::assertFalse($capture->capturedOptions->toRaw);
  }

  // ---------- 模型层：修改器与 Raw 值交互 ----------

  /**
   * 模型 increment 时 Raw 表达式不进修改器、extra 标量照常进修改器：
   * Raw 被修改器转换会抛 TypeError 或把原子表达式静默替换为标量
   */
  public function testIncrementSkipsMutatorForRawButAppliesForExtra(): void
  {
    $model = new class extends Model {
      /** @var array<string,string> 修改器收到的值类型记录，键为字段名 */
      public array $mutatorReceived = [];

      protected string $table = 'user';

      public function setScoreAttr(mixed $value): mixed
      {
        $this->mutatorReceived['score'] = get_debug_type($value);
        return $value;
      }

      public function setLevelAttr(mixed $value): mixed
      {
        $this->mutatorReceived['level'] = get_debug_type($value);
        return ((int)$value) + 100;
      }
    };
    $manager = App::factory()->make(DbManager::class);
    $manager->addChannel('default', $this->makeCaptureChannel());
    $manager->setDebug(false);

    $this->runQuietly(fn () => $model->query
      ->where('id', 1)
      ->increment('score', 5, ['level' => 2]));

    // Raw 表达式跳过修改器（不会以 object 类型触达），extra 标量照常进入修改器
    self::assertSame([], $model->mutatorReceived['score'] ?? []);
    self::assertSame('int', $model->mutatorReceived['level']);
  }

  /**
   * extra 与自增列同名时显式拦截，防止标量覆盖原子表达式
   */
  public function testIncrementRejectsDuplicateExtraKey(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('不能包含自增列');
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->where('id', 1)
      ->increment('score', 5, ['score' => 99]));
  }
}
