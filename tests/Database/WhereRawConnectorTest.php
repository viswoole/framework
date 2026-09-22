<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use Viswoole\Database\Channel\PDO\PDOChannel;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * whereRaw 连接符回归测试
 *
 * 修复前缺陷：whereRaw() 将 Raw 直接压入 where 列表，Raw 对象无 connector 信息，
 * SqlBuilder::parseWhereItem 的 Raw 分支裸返回 SQL 片段——whereRaw 与 where 链式
 * 混用时条件间缺失 AND/OR 连接符，生成 "WHERE `a` = 1 b & ? > 0" 的坏 SQL（MySQL 1064）。
 *
 * 修复后：Raw 携带 connector（默认 AND，可显式指定），parseWhereItem 按连接符拼接；
 * 首条件的前导连接符由 parseWhere 既有剥离逻辑移除；新增 orWhereRaw 便捷入口。
 */
class WhereRawConnectorTest extends TestCase
{
  use ProvidesSqliteStatement;

  /**
   * 构建捕获 build() 收到 Options 的通道替身
   *
   * 查询链路为 table()->whereRaw(...)->value() → runCrud → build(Options) → execute，
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
   * whereRaw 与 where 混用时，Raw 条件应携带 AND 连接符（修复前生成无连接符坏 SQL）
   */
  public function testWhereRawMixedWithWhereKeepsAndConnector(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->where('status', 1)
      ->whereRaw('score & ? > 0', [1])
      ->value('id'));

    $sql = $this->buildSql($capture->capturedOptions);
    // Raw 绑定值经 Raw::merge 内联为字面量（score & 1）
    self::assertStringContainsString('AND score & 1 > 0', $sql);
  }

  /**
   * whereRaw 作为首条件时，前导连接符应被剥离（不产生 "WHERE AND ..."）
   */
  public function testLeadingWhereRawHasNoLeadingConnector(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->whereRaw('score & ? > 0', [1])
      ->value('id'));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('WHERE score & 1 > 0', $sql);
  }

  /**
   * whereRaw 显式指定 OR 连接符时应按 OR 拼接
   */
  public function testWhereRawExplicitOrConnector(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->where('status', 1)
      ->whereRaw('score > ?', [60], 'OR')
      ->value('id'));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('OR score > 60', $sql);
  }

  /**
   * orWhereRaw 便捷方法应按 OR 拼接
   */
  public function testOrWhereRawUsesOrConnector(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->runQuietly(fn () => $capture->table('users')
      ->where('status', 1)
      ->orWhereRaw('score > ?', [60])
      ->value('id'));

    $sql = $this->buildSql($capture->capturedOptions);
    self::assertStringContainsString('OR score > 60', $sql);
  }

  /**
   * 无效连接符应抛出 InvalidArgumentException
   */
  public function testWhereRawRejectsInvalidConnector(): void
  {
    $capture = $this->makeCaptureChannel();
    $this->expectException(InvalidArgumentException::class);
    $this->runQuietly(fn () => $capture->table('users')
      ->whereRaw('a = ?', [], 'XOR'));
  }
}
