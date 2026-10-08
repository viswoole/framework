<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Override;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Channel;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;
use Viswoole\Database\Transaction\XaDriver;
use Viswoole\Database\Transaction\XaJournal;
use Viswoole\Database\Transaction\XaRecovery;

/**
 * 批次 4（XA 事务与恢复路径）加固回归测试
 *
 * 覆盖三项修复：
 *  1. B4-03 journal/恢复路径的通道连接借出（pop）超时返回 false 时应快速失败，
 *     且不得把 false 归还连接池污染全池
 *  2. B4-02 XAER_NOTA（MySQL 错误码 1397）识别：mysqli 路径异常仅携带 errno
 *     而无服务器错误文本时，isNotExists 仍应凭错误码判定幂等达成
 *  3. B4-04 恢复路径执行终结语句前，对 XA RECOVER 读回的 xid 整体做白名单校验
 *     （前缀命中 journal gtrid 后的尾部片段不得内联进 SQL）
 */
class XaHardeningTest extends TestCase
{
  /**
   * 模拟池超时（pop 返回 false）的通道替身，记录所有 put 归还值
   */
  private function makeTimeoutChannel(): TimeoutChannelStub
  {
    return new TimeoutChannelStub();
  }

  /**
   * 静默执行（XA 路径 echo_log 输出会污染 PHPUnit 输出）
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

  // ---------------------------------------------------------------- B4-03

  /**
   * journal 通道借出连接超时（pop 返回 false）应快速失败，且不得向池归还 false
   */
  public function testJournalPopTimeoutDoesNotPollutePool(): void
  {
    $channel = $this->makeTimeoutChannel();
    $journal = new XaJournal($channel, 'xa_journal_test');

    $this->runQuietly(function () use ($journal) {
      try {
        $journal->markPrepared('gtrid123');
        self::fail('借出连接超时应抛出 DbException');
      } catch (DbException $e) {
        self::assertStringContainsString('超时', $e->getMessage());
      }
    });

    self::assertSame([], $channel->puts, 'pop 失败不得向连接池归还非法连接（false 会污染全池）');
  }

  // ---------------------------------------------------------------- B4-02

  /**
   * mysqli 路径异常仅携带 errno=1397（XAER_NOTA）而无服务器错误文本时，
   * isNotExists 应凭错误码识别幂等达成（修复前误判为 1390）
   */
  public function testIsNotExistsRecognizesMysqliNotAByErrno(): void
    {
      $e = new DbException("XA 语句执行失败：XA COMMIT 'gtridabc-b1'", 1397);
      self::assertTrue(XaDriver::isNotExists($e), 'errno=1397（XAER_NOTA）应识别为已终结');

      // 非幂等错误码不得误判
      $deadlock = new DbException('XA 语句执行失败：XA COMMIT', 1213);
      self::assertFalse(XaDriver::isNotExists($deadlock), '死锁（1213）不是 XAER_NOTA');
    }

  // ---------------------------------------------------------------- B4-04

  /**
   * 恢复路径对前缀命中 journal 的 xid 应整体过白名单：
   * 合法 gtrid 前缀 + 恶意尾部片段的 xid 不得执行终结语句
   */
  public function testRecoveryRejectsNonWhitelistedXidTail(): void
  {
    $rows = [
      'gtridabc' => ['state' => XaJournal::STATE_PREPARED, 'branches' => []],
    ];
    $xid = "gtridabc-'; DROP TABLE viswoole_xa_journal;--";

    $this->runQuietly(function () use (&$rows, $xid) {
      $method = new ReflectionMethod(XaRecovery::class, 'recoverXid');
      $args = [new stdClass(), $xid, &$rows, false, []];
      $method->invokeArgs(null, $args);
    });

    self::assertArrayNotHasKey(
      'blocked',
      $rows['gtridabc'],
      '非法 xid 应被白名单直接拒绝，不应尝试执行终结语句（blocked 说明已进入执行路径）'
    );
  }
}

/**
 * 模拟连接池超时的通道替身：pop 恒返回 false（Swoole PDOPool 超时语义），
 * 记录所有 put 归还值，用于断言 pop 失败后不向池归还非法连接
 */
class TimeoutChannelStub extends Channel
{
  /** @var array<int,mixed> 记录归还到池的值 */
  public array $puts = [];

  #[Override]
  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): PDOStatementProxy|PDOStatement|int|string {
    return 0;
  }

  #[Override]
  public function pop(string $type): mixed
  {
    return false;
  }

  #[Override]
  public function put(mixed $connect): void
  {
    $this->puts[] = $connect;
  }

  #[Override]
  public function build(Options $options): Raw
  {
    return new Raw('', []);
  }
}
