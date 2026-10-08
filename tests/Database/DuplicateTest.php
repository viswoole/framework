<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Tests\Database;

use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\DriverType;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * duplicate() ON DUPLICATE KEY UPDATE 回归测试
 *
 * 覆盖：标准 upsert SQL 生成与绑定参数顺序 / Raw 表达式更新值 /
 * 批量写入更新子句仅拼接一次 / 空数据与 replace() 互斥守卫 /
 * 非 MySQL 驱动构建期拦截
 */
class DuplicateTest extends TestCase
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
   *
   * PDOChannel 以全默认参数实例化：连接池惰性创建且不预填充，
   * 构建过程纯字符串操作，不触碰数据库。
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
   * 标准 upsert：生成 ON DUPLICATE KEY UPDATE 子句，更新值走参数绑定
   */
  public function testDuplicateBuildsUpsertSql(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->duplicate(['score' => 10])
      ->toRaw()
      ->insert(['name' => 'think']));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      'INSERT INTO `user` (`name`) VALUES (\'think\') ON DUPLICATE KEY UPDATE `score` = 10',
      $sql
    );
    // 绑定参数顺序：插入值在前，更新值在后
    $raw = (new PDOChannel())->build($capture->capturedOptions);
    self::assertSame(['think', 10], $raw->bindings);
  }

  /**
   * 更新值支持 Raw 原生表达式（如自增），不进入绑定参数
   */
  public function testDuplicateSupportsRawExpression(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->duplicate(['num' => new Raw('num + 1')])
      ->toRaw()
      ->insert(['id' => 1]));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('ON DUPLICATE KEY UPDATE `num` = num + 1', $sql);
    $raw = (new PDOChannel())->build($capture->capturedOptions);
    // Raw 表达式的绑定参数并入主绑定列表：插入值 1 + Raw 无参数
    self::assertSame([1], $raw->bindings);
  }

  /**
   * 批量写入：VALUES 多行共用一条更新子句，绑定参数按行序展开后追加更新值
   */
  public function testDuplicateWithBatchInsert(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->duplicate(['score' => 10])
      ->toRaw()
      ->insert([['name' => 'a'], ['name' => 'b']]));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      'INSERT INTO `user` (`name`) VALUES (\'a\'), (\'b\') ON DUPLICATE KEY UPDATE `score` = 10',
      $sql
    );
    $raw = (new PDOChannel())->build($capture->capturedOptions);
    self::assertSame(['a', 'b', 10], $raw->bindings);
  }

  /**
   * 空更新数据在链式入口即拦截
   */
  public function testDuplicateRejectsEmptyData(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('更新数据不能为空');
    $capture = $this->makeCaptureChannel();
    $capture->table('user')->duplicate([]);
  }

  /**
   * replace() 在前时 duplicate() 链式入口拦截
   */
  public function testDuplicateRejectsReplaceFirst(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('互斥');
    $capture = $this->makeCaptureChannel();
    $capture->table('user')->replace()->duplicate(['score' => 1]);
  }

  /**
   * duplicate() 在前、replace() 在后时链式入口拦截：replace() 入口守卫检测到
   * 已设置的更新数据立即抛出，构建期 parseDuplicate 亦兜底拦截同一误用
   */
  public function testDuplicateRejectsReplaceLast(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('互斥');
    $channel = new PDOChannel();
    $channel->table('user')
      ->duplicate(['score' => 1])
      ->replace()
      ->toRaw()
      ->insert(['name' => 'think']);
  }

  /**
   * 非 MySQL 驱动（PG/SQLite 的冲突写语法为 ON CONFLICT）构建期拦截，
   * 避免静默降级为普通 INSERT 造成 upsert 语义偏差
   */
  public function testDuplicateRejectsNonMysqlDriver(): void
  {
    $options = new Options('user', 'id');
    $options->type = 'insert';
    $options->data = ['name' => 'think'];
    $options->duplicate = ['score' => 10];

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('仅支持 MySQL 驱动');
    (new PDOChannel(DriverType::SQLite))->build($options);
  }

  // ---------- VALUES 行别名（MySQL 8.0.19+） ----------

  /**
   * rowAlias 生成 VALUES (...) AS alias，更新子句可经 Raw 以 alias.col 引用待插入值
   */
  public function testDuplicateRowAliasReferencesInsertValues(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('sys_platform_configs')
      ->duplicate([
        'config_value' => new Raw('new.config_value'),
        'update_by'    => new Raw('new.update_by'),
        'update_time'  => new Raw('new.update_time'),
      ], rowAlias: 'new')
      ->toRaw()
      ->insert([
        'config_key'   => 'site_name',
        'config_value' => 'viswoole',
        'update_by'    => 1,
        'update_time'  => '2026-10-08 00:00:00',
      ]));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      'INSERT INTO `sys_platform_configs` (`config_key`, `config_value`, `update_by`, `update_time`)'
      . " VALUES ('site_name', 'viswoole', 1, '2026-10-08 00:00:00') AS `new`"
      . ' ON DUPLICATE KEY UPDATE `config_value` = new.config_value,'
      . ' `update_by` = new.update_by, `update_time` = new.update_time',
      $sql
    );
  }

  /**
   * 批量写入时行别名仅拼接一次，对每行生效
   */
  public function testDuplicateRowAliasWithBatchInsert(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->duplicate(['score' => new Raw('new.score')], rowAlias: 'new')
      ->toRaw()
      ->insert([['name' => 'a', 'score' => 1], ['name' => 'b', 'score' => 2]]));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertSame(
      'INSERT INTO `user` (`name`, `score`) VALUES (\'a\', 1), (\'b\', 2) AS `new`'
      . ' ON DUPLICATE KEY UPDATE `score` = new.score',
      $sql
    );
  }

  /**
   * 非法行别名（含 SQL 片段字符）在链式入口即拦截
   */
  public function testDuplicateRejectsInvalidRowAlias(): void
  {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('无效的 VALUES 行别名');
    $capture = $this->makeCaptureChannel();
    $capture->table('user')->duplicate(['score' => 1], rowAlias: 'new; DROP TABLE user');
  }

  /**
   * 未传 rowAlias 时保持既有 SQL 形态（无 AS 别名），向后兼容
   */
  public function testDuplicateWithoutRowAliasKeepsLegacySql(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('user')
      ->duplicate(['score' => 10])
      ->toRaw()
      ->insert(['name' => 'think']));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringNotContainsString(' AS ', $sql);
  }
}
