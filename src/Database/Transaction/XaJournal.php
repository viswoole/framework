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

use Throwable;
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
   * @var \WeakMap<Channel,array<string,true>> 进程级已建表缓存（键：通道实例，值：表名 → 已确认）
   *
   * journal 实例经 fromConfig() 每事务新建，实例级缓存对"每事务一次"失效，
   * 导致每个 XA 事务的提交关键路径都执行一次 CREATE TABLE（DDL 往返 + MDL）。
   * 提升为进程级缓存后整个进程只建一次表。
   * 以通道实例为 WeakMap 键而非 spl_object_id：对象 ID 会被 GC 复用，键碰撞
   * 会把 A 通道的 schema 结论误用于 B 通道；WeakMap 键随实例同生命周期销毁
   */
  private static \WeakMap $ensuredTables;
  /**
   * @var \WeakMap<Channel,array<string,bool>> 进程级 heartbeat_at 列存在性缓存（键：通道实例，值：表名 → 列是否存在）
   *
   * 写路径用于旧表一次性迁移判定，读路径（all）用于选择兼容的 SELECT 列集。
   * 与 $ensuredTables 分离：恢复任务可能在任何写操作之前运行（读路径不建表），
   * 两者的"已确认"语义不能互相替代
   */
  private static \WeakMap $heartbeatColumns;
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
   * 从应用配置构建 journal 实例
   *
   * @return static 配置的 journal 实例
   * @throws DbException journal 通道未注册时抛出
   */
  public static function fromConfig(): XaJournal
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
   * 在独立连接上执行 journal 写语句（autocommit，语句返回即落盘）
   *
   * 每次写入都取新连接：journal 语句出现在 XA 提交关键路径上，
   * 复用池化连接会在语句间引入被其他协程借走的不确定性。
   *
   * @param string $sql 写语句
   * @return int 受影响行数（markPrepared 依赖它判定提交意图行是否仍存在）
   * @throws DbException 借出失败或执行失败时抛出
   */
  private function executeOnFreshConnection(string $sql): int
  {
    $this->ensureTable();
    $connect = $this->popJournalConnection();
    try {
      return XaDriver::execute($connect, $sql);
    } finally {
      $this->channel->put($connect);
    }
  }

  /**
   * 确保 journal 表存在且包含心跳列（幂等 DDL，随事务低频执行）
   *
   * CREATE TABLE IF NOT EXISTS 对旧版本已存在的表是 no-op，心跳列需单独探测补齐
   * （ALTER ... ADD COLUMN）：迁移一次性执行，未使用 XA 的项目零副作用不变
   */
  private function ensureTable(): void
  {
    self::$ensuredTables ??= new \WeakMap();
    self::$heartbeatColumns ??= new \WeakMap();
    // 进程级缓存 + 实例级缓存：同一进程内同一表只执行一次建表/迁移 DDL
    $tables = self::$ensuredTables[$this->channel] ?? [];
    if (isset($tables[$this->table])) {
      $this->tableEnsured = true;
      return;
    }
    if ($this->tableEnsured) return;
    $connect = $this->popJournalConnection();
    try {
      XaDriver::execute(
        $connect, "CREATE TABLE IF NOT EXISTS {$this->quotedTable()} ("
        . 'gtrid varchar(64) NOT NULL COMMENT \'全局事务ID（分支xid共享此前缀）\', '
        . 'state tinyint NOT NULL COMMENT \'1=prepare_start 2=prepared\', '
        . 'branches text NOT NULL COMMENT \'分支xid列表JSON\', '
        . 'created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'heartbeat_at timestamp(3) NULL DEFAULT NULL '
        . 'COMMENT \'协调者心跳（PREPARE/COMMIT 关键阶段刷新，NULL=尚未刷新）\', '
        . 'PRIMARY KEY (gtrid)'
        . ') ENGINE=InnoDB'
      );
    } finally {
      $this->channel->put($connect);
    }
    // 旧版本表迁移：补心跳列（恢复任务据心跳判定协调者是否已死，见 heartbeat）
    if (!$this->heartbeatColumnExists()) {
      $connect = $this->popJournalConnection();
      $alterError = null;
      try {
        XaDriver::execute(
          $connect, "ALTER TABLE {$this->quotedTable()} ADD COLUMN heartbeat_at "
          . "timestamp(3) NULL DEFAULT NULL "
          . "COMMENT '协调者心跳（PREPARE/COMMIT 关键阶段刷新，NULL=尚未刷新）'"
        );
      } catch (Throwable $e) {
        // 并发迁移竞争（如滚动重启期多 worker 首写同一旧表）：败者得到
        // Duplicate column（MySQL 1060）。探测结论在 ALTER 前已被缓存为 false，
        // 必须先失效缓存，否则该 worker 后续每次写入都命中 false 重跑 ALTER
        // 并持续失败（worker 级 XA 故障直至重启）
        $alterError = $e;
        $columns = self::$heartbeatColumns[$this->channel] ?? [];
        unset($columns[$this->table]);
        self::$heartbeatColumns[$this->channel] = $columns;
      } finally {
        $this->channel->put($connect);
      }
      if ($alterError !== null) {
        // 连接归还后再绕缓存重探测：列已被并发方补齐则视为迁移完成，否则原样重抛
        if (!$this->heartbeatColumnExists(useCache: false)) {
          throw $alterError;
        }
      }
    }
    $columns = self::$heartbeatColumns[$this->channel] ?? [];
    $columns[$this->table] = true;
    self::$heartbeatColumns[$this->channel] = $columns;
    $tables[$this->table] = true;
    self::$ensuredTables[$this->channel] = $tables;
    $this->tableEnsured = true;
  }

  /**
   * 从 journal 通道借出连接（带借出失败防护）
   *
   * 池借出超时返回 false（Swoole 连接池语义）：必须快速失败且不得把
   * false 传入归还路径——污染连接池后其他协程借到 false 会直接崩溃
   *
   * @return object 可用的底层连接
   * @throws DbException 借出失败（池超时）时抛出
   */
  private function popJournalConnection(): object
  {
    $connect = $this->channel->pop('write');
    if (!is_object($connect)) {
      throw new DbException('journal 通道连接借出失败（可能连接池超时），本次操作放弃');
    }
    return $connect;
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
   * 探测 journal 表是否已包含 heartbeat_at 心跳列（结果进程级缓存）
   *
   * @param bool $useCache false 时绕过缓存重新探测（迁移竞争失败后的权威重探）
   * @return bool 列存在返回 true（表不存在时亦返回 false，调用方各自处理）
   * @throws DbException 探测查询失败时抛出
   */
  private function heartbeatColumnExists(bool $useCache = true): bool
  {
    self::$heartbeatColumns ??= new \WeakMap();
    $columns = self::$heartbeatColumns[$this->channel] ?? [];
    if ($useCache && array_key_exists($this->table, $columns)) {
      return $columns[$this->table];
    }
    $connect = $this->popJournalConnection();
    try {
      // information_schema 精确匹配（与 tableExists 同源防御，表名来自配置）
      $table = str_replace("'", "''", $this->table);
      $rows = XaDriver::query(
        $connect,
        "SELECT COUNT(*) AS cnt FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = '$table' "
        . "AND column_name = 'heartbeat_at'"
      );
    } finally {
      $this->channel->put($connect);
    }
    $exists = (int)($rows[0]['cnt'] ?? 0) > 0;
    $columns[$this->table] = $exists;
    self::$heartbeatColumns[$this->channel] = $columns;
    return $exists;
  }

  /**
   * 将行状态标记为 prepared（PREPARE 全部成功后调用）
   *
   * @param string $gtrid 全局事务 ID
   * @return bool 行存在且状态已更新返回 true；返回 false 意味着提交意图行
   *              已被恢复任务删除（行写入后 PREPARE 停顿超过处置阈值）——
   *              协调者必须立即中止提交并全体回滚，此时提交意图已丢失，
   *              继续推进 COMMIT 会导致分支永久悬挂或被误回滚
   * @throws DbException UPDATE 执行失败时抛出
   */
  public function markPrepared(string $gtrid): bool
  {
    self::assertValidGtrid($gtrid);
    // state 1→2 必然变化，受影响行数即行存在性判定（该写方法每事务仅调用一次，
    // 不存在"状态已是 2 导致 changed=0"的误判路径）
    return $this->executeOnFreshConnection(
        "UPDATE {$this->quotedTable()} SET state = " . self::STATE_PREPARED . " WHERE gtrid = '$gtrid'"
      ) > 0;
  }

  /**
   * 刷新 journal 行心跳（PREPARE/COMMIT 关键阶段前调用）
   *
   * 恢复任务仅处置心跳停跳的 prepare_start 行：协调者在每个分支的
   * PREPARE/COMMIT 前刷新心跳，"行仍被活跃协调者持有"的判定从
   * created_at 冷却期（写入后 60s 内一律不处置）精确为"距上次关键阶段
   * 刷新 ≤ TTL"，消除对超长停顿（慢 PREPARE/网络重试）的活跃事务的误处置。
   * 不检查受影响行数：UPDATE 未命中（行已被恢复任务删除）与时间戳未变化
   * （同一毫秒内两次刷新）都返回 0，无法区分且二者均无需中止提交——
   * 行存在性的权威判定位于 markPrepared。
   *
   * @param string $gtrid 全局事务 ID
   * @throws DbException 刷新失败（journal 不可达等）时抛出
   */
  public function heartbeat(string $gtrid): void
  {
    self::assertValidGtrid($gtrid);
    $this->executeOnFreshConnection(
      "UPDATE {$this->quotedTable()} SET heartbeat_at = NOW(3) WHERE gtrid = '$gtrid'"
    );
  }

  /**
   * 读取 journal 行状态（提交侧 XAER_NOTA 二义性核对与恢复处置前重读）
   *
   * @param string $gtrid 全局事务 ID
   * @return int|null 行状态（STATE_PREPARE_START / STATE_PREPARED），行不存在返回 null
   * @throws DbException journal 读取失败时抛出
   */
  public function stateOf(string $gtrid): ?int
  {
    self::assertValidGtrid($gtrid);
    $connect = $this->popJournalConnection();
    try {
      $rows = XaDriver::query(
        $connect, "SELECT state FROM {$this->quotedTable()} WHERE gtrid = '$gtrid'"
      );
    } finally {
      $this->channel->put($connect);
    }
    if ($rows === []) return null;
    return (int)($rows[0]['state'] ?? self::STATE_PREPARE_START);
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
   * 只探测不建表：journal 表不存在（该项目从未使用过 XA 事务）时返回空数组，
   * 恢复任务据此静默返回——建表仅发生在 XA 提交路径（首次 startXaTransaction），
   * 保证未使用 XA 的项目零副作用（不建表、零 DDL）。
   * 心跳列按实际 schema 兼容：旧版本表（未迁移）返回 heartbeat_at = null，
   * 恢复任务对 null 心跳回退 created_at 冷却期判定。
   *
   * @return array<string,array{state:int,branches:string[],created_at:string,heartbeat_at:string|null}> 键为 gtrid
   */
  public function all(): array
  {
    if (!$this->tableExists()) return [];
    $hasHeartbeat = $this->heartbeatColumnExists();
    $connect = $this->channel->pop('write');
    try {
      $rows = XaDriver::query(
        $connect, 'SELECT gtrid, state, branches, created_at'
        . ($hasHeartbeat ? ', heartbeat_at' : '') . " FROM {$this->quotedTable()}"
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
        // isset 对 null 返回 false：非空字符串才作为心跳时间，空串/null/缺列均视为无心跳
        'heartbeat_at' => $hasHeartbeat && isset($row['heartbeat_at']) && $row['heartbeat_at'] !== ''
          ? (string)$row['heartbeat_at']
          : null,
      ];
    }
    return $result;
  }

  /**
   * 探测 journal 表是否已存在（只读，不执行 DDL）
   *
   * @return bool 表存在返回 true
   */
  private function tableExists(): bool
  {
    if ($this->tableEnsured) return true;
    $connect = $this->popJournalConnection();
    try {
      // information_schema 精确匹配（SHOW TABLES LIKE 的 _/% 通配符会误匹配表名）；
      // journal 通道本就要求 MySQL（见使用前提），information_schema 可用。
      // 表名来自配置，字符串字面量内单引号需转义（与写路径 quotedTable 同级防御）
      $table = str_replace("'", "''", $this->table);
      $rows = XaDriver::query(
        $connect,
        "SELECT COUNT(*) AS cnt FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name = '$table'"
      );
    } finally {
      $this->channel->put($connect);
    }
    return (int)($rows[0]['cnt'] ?? 0) > 0;
  }
}
