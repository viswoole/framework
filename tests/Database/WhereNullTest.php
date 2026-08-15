<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Override;
use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * whereNull/whereNotNull 构建回归测试
 *
 * 修复前缺陷：whereNull()/whereNotNull() 内部调用 where($column, 'IS NULL', null)，
 * 被 where() 的"两参调用"兼容分支（$value === null 时把 operator 挪给 value）
 * 破坏为 {operator: '=', value: 'IS NULL'}，最终生成 "col = 'IS NULL'" 坏 SQL
 * （MySQL 报 Incorrect DATETIME value），且 SqlBuilder::parseWhereItem 的
 * null 分支成为死代码——所有启用软删除的模型查询均会失败。
 *
 * 本测试同时验证两参兼容写法（where(col, value)）不受修复影响。
 */
class WhereNullTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建捕获 build() 收到 Options 的通道替身
   *
   * 查询链路为 table()->whereNull()->value() → runCrud → build(Options) → execute，
   * 在 build 处截获 Options 引用，execute 返回 Sqlite 替身语句。
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
        return new Raw('SELECT * FROM `users`', []);
      }
    };
  }

  /**
   * 用真实 SqlBuilder（经 PDOChannel::build）把捕获的 Options 构建为 SQL 字符串
   *
   * PDOChannel 以全默认参数实例化：连接池惰性创建且不预填充，构建过程纯字符串操作，不触碰数据库。
   */
  private function buildSql(Options $options): string
  {
    return (new PDOChannel())->build($options)->toString();
  }

  /**
   * 静默执行查询，捕获框架 echo 的 SQL 运行日志（避免 PHPUnit 标记 risky）
   *
   * @param callable $fn 无参回调，内部执行触发查询的链式调用
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
   * whereNull 应存 {operator:'IS NULL', value:null} 并构建 `col` IS NULL
   */
  public function testWhereNullBuildsIsNullSql(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn() => $capture->table('users')->whereNull('deleted_at')->value('id'));

    $where = $capture->capturedOptions->where[0] ?? null;
    self::assertNotNull($where, '查询选项中应存在 where 条件');
    self::assertSame('IS NULL', $where['operator']);
    self::assertNull($where['value']);

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('`deleted_at` IS NULL', $sql);
    // 修复前缺陷特征：'IS NULL' 被当作绑定值出现在 SQL 中
    self::assertStringNotContainsString("'IS NULL'", $sql);
  }

  /**
   * whereNotNull 应存 {operator:'IS NOT NULL', value:null} 并构建 `col` IS NOT NULL
   */
  public function testWhereNotNullBuildsIsNotNullSql(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn() => $capture->table('users')->whereNotNull('unionid')->value('id'));

    $where = $capture->capturedOptions->where[0] ?? null;
    self::assertNotNull($where, '查询选项中应存在 where 条件');
    self::assertSame('IS NOT NULL', $where['operator']);
    self::assertNull($where['value']);

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('`unionid` IS NOT NULL', $sql);
    self::assertStringNotContainsString("'IS NOT NULL'", $sql);
  }

  /**
   * 两参兼容写法 where(col, value) 不受修复影响（operator 归一为 =，value 原样保留）
   */
  public function testTwoArgWhereCompatUnaffected(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn() => $capture->table('users')->where('status', 1)->value('id'));

    $where = $capture->capturedOptions->where[0] ?? null;
    self::assertNotNull($where, '查询选项中应存在 where 条件');
    self::assertSame('=', $where['operator']);
    self::assertSame(1, $where['value']);

    // SqlBuilder 对 int 标量直接内联（非参数绑定）
    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('`status` = 1', $sql);
  }

  /**
   * 两参兼容写法 where(col, [数组]) 自动转 IN 不受修复影响
   */
  public function testTwoArgArrayWhereStillTranslatesToIn(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn() => $capture->table('users')->where('id', [1, 2, 3])->value('id'));

    $where = $capture->capturedOptions->where[0] ?? null;
    self::assertNotNull($where, '查询选项中应存在 where 条件');
    self::assertSame('IN', $where['operator']);
    self::assertSame([1, 2, 3], $where['value']);
  }
}
