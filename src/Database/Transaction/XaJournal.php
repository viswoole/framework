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

declare(strict_types=1);

namespace Viswoole\Database\Transaction;

use Viswoole\Core\App;
use Viswoole\Database\Channel;
use Viswoole\Database\DbManager;
use Viswoole\Database\Exception\DbException;
use function config;

/**
 * XA 提交意图日志（journal）
 *
 * 两阶段提交的崩溃恢复基石：在 XA PREPARE 之前持久化"本事务打算提交"的意图，
 * 崩溃后恢复任务据此决定对未决分支执行 XA COMMIT 还是 XA ROLLBACK——
 * 数据库只知道事务处于 prepared 状态，提交意图只存在于应用进程中，
 * 进程崩溃即丢失，必须先于 PREPARE 落到耐崩溃的存储（MySQL 表）。
 *
 * 落库位置：独立通道（默认 default，可经 database.xa.journal_channel 配置）。
 * 写入必须绕过 ConnectManager 直接从通道取连接：journal 连接若加入正在进行的
 * XA 事务，崩溃时意图记录会随事务一起未决，journal 形同虚设。
 * 连接保持 autocommit（PDO 默认），每条语句独立落盘。
 *
 * SQL 采用内联值而非参数绑定：journal 的 gtrid 在写方法入口经
 * assertValidGtrid 白名单校验（[0-9a-zA-Z._-]），状态为整数、分支 xid 为
 * 框架生成值——校验后无用户可控内容进入 SQL，注入面为零。
 */
final class XaJournal
{
  /** journal 行状态：PREPARE 前写入，恢复时对未决分支执行 XA ROLLBACK */
  public const int STATE_PREPARE_START = 1;
  /** journal 行状态：PREPARE 全部成功，恢复时对未决分支执行 XA COMMIT */
  public const int STATE_PREPARED = 2;

  /**
   * @var bool 表已确认存在（实例级缓存，避免每次写语句都执行 DDL）
   */
  private bool $tableEnsured = false;

  /**
   * @param Channel $channel journal 所在数据库通道
   * @param string $table journal 表名（可经 database.xa.journal_table 配置）
   */
  public function __construct(
    private readonly Channel $channel,
    private readonly string  $table = 'viswoole_xa_journal'
  )
  {
  }

  /**
   * 校验 gtrid 合法性（内联 SQL 的注入防线）
   *
   * journal 写方法的 gtrid 来自两类来源：框架生成（必然合法）与
   * journal 表读回的值（库内数据可能被篡改）。校验白名单与框架生成格式
   * （[0-9a-zA-Z._-]，见 XaContext）对齐，非法值直接拒绝。
   *
   * @param string $gtrid 待校验的全局事务 ID
   * @throws DbException 校验失败时抛出
   */
  private static function assertValidGtrid(string $gtrid): void
  {
    if (!preg_match('/^[0-9a-zA-Z._-]{1,64}$/', $gtrid)) {
      throw new DbException("非法的 XA 全局事务 ID（gtrid）：" . addcslashes($gtrid, "\0..\37"));
    }
  }

  /**
   * 从应用配置构建 journal 实例
   *
   * @return static 配置的 journal 实例
   * @throws DbException journal 通道未注册时抛出
   */
  public static function fromConfig(): static
  {
    $db = App::factory()->make(DbManager::class);
    $name = config('database.xa.journal_channel');
    $channel = is_string($name) && $name !== '' ? $db->channel($name) : $db->channel();
    return new XaJournal(
      $channel, (string)config('database.xa.journal_table', 'viswoole_xa_journal')
    );
  }

  /**
   * 写入 prepare_start 行（PREPARE 前调用）
   *
   * @param string $gtrid 全局事务 ID
   * @param string[] $xids 分支 xid 列表（信息列，供运维排查，恢复以 XA RECOVER 实际结果为准）
   */
  public function recordPrepareStart(string $gtrid, array $xids): void
  {
    self::assertValidGtrid($gtrid);
    $this->executeOnFreshConnection(
      "INSERT INTO {$this->quotedTable()} (gtrid, state, branches) VALUES ('$gtrid', "
      . self::STATE_PREPARE_START . ", '" . json_encode($xids) . "')"
    );
  }

  /**
   * 在独立连接上执行 journal 写语句（autocommit，语句返回即落盘）
   *
   * 每次写入都取新连接：journal 语句出现在 XA 提交关键路径上，
   * 复用池化连接会在语句间引入被其他协程借走的不确定性。
   *
   * @param string $sql 写语句
   */
  private function executeOnFreshConnection(string $sql): void
  {
    $this->ensureTable();
    $connect = $this->channel->pop('write');
    try {
      XaDriver::execute($connect, $sql);
    } finally {
      $this->channel->put($connect);
    }
  }

  /**
   * 确保 journal 表存在（幂等 DDL，随事务低频执行）
   */
  private function ensureTable(): void
  {
    // 实例级缓存：同一 journal 实例的建表只执行一次，
    // 事务提交关键路径上的后续写语句不再重复 DDL
    if ($this->tableEnsured) return;
    $connect = $this->channel->pop('write');
    try {
      XaDriver::execute(
        $connect, "CREATE TABLE IF NOT EXISTS {$this->quotedTable()} ("
        . 'gtrid varchar(64) NOT NULL COMMENT \'全局事务ID（分支xid共享此前缀）\', '
        . 'state tinyint NOT NULL COMMENT \'1=prepare_start 2=prepared\', '
        . 'branches text NOT NULL COMMENT \'分支xid列表JSON\', '
        . 'created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'PRIMARY KEY (gtrid)'
        . ') ENGINE=InnoDB'
      );
    } finally {
      $this->channel->put($connect);
    }
    $this->tableEnsured = true;
  }

  /**
   * 反引号包裹表名（防御性：表名来自配置）
   *
   * @return string 包裹后的表名
   */
  private function quotedTable(): string
  {
    return '`' . str_replace('`', '``', $this->table) . '`';
  }

  /**
   * 将行状态标记为 prepared（PREPARE 全部成功后调用）
   *
   * @param string $gtrid 全局事务 ID
   */
  public function markPrepared(string $gtrid): void
  {
    self::assertValidGtrid($gtrid);
    $this->executeOnFreshConnection(
      "UPDATE {$this->quotedTable()} SET state = " . self::STATE_PREPARED . " WHERE gtrid = '$gtrid'"
    );
  }

  /**
   * 删除 journal 行（事务终结后调用，幂等）
   *
   * @param string $gtrid 全局事务 ID
   */
  public function remove(string $gtrid): void
  {
    self::assertValidGtrid($gtrid);
    $this->executeOnFreshConnection("DELETE FROM {$this->quotedTable()} WHERE gtrid = '$gtrid'");
  }

  /**
   * 读取全部 journal 行（恢复任务调用）
   *
   * @return array<string,array{state:int,branches:string[],created_at:string}> 键为 gtrid
   */
  public function all(): array
  {
    $this->ensureTable();
    $connect = $this->channel->pop('write');
    try {
      $rows = XaDriver::query(
        $connect, "SELECT gtrid, state, branches, created_at FROM {$this->quotedTable()}"
      );
    } finally {
      $this->channel->put($connect);
    }
    $result = [];
    foreach ($rows as $row) {
      $gtrid = (string)($row['gtrid'] ?? '');
      if ($gtrid === '') continue;
      $branches = json_decode((string)($row['branches'] ?? '[]'), true);
      $result[$gtrid] = [
        'state' => (int)($row['state'] ?? self::STATE_PREPARE_START),
        'branches' => is_array($branches) ? $branches : [],
        'created_at' => (string)($row['created_at'] ?? ''),
      ];
    }
    return $result;
  }
}
