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

declare(strict_types=1);

namespace Viswoole\Database\Channel\PDO;

use PDOException;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Database\ConnectManager;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Raw;
use Viswoole\Monitor\TraceContext;

/**
 * PDO 语句执行边界
 *
 * 职责：承载 PDOChannel::execute 的完整实现，并按当前请求上下文采集
 * SQL 执行记录。
 * 边界：计时包含连接池借出等待、预编译、绑定及执行，不包含执行方法
 * 返回后调用方的 fetchAll() 等取数操作；绑定值默认脱敏，是否记录由
 * record_bindings 配置决定。
 */
trait ExecutesStatements
{
  /**
   * 执行参数化 SQL 语句，INSERT/REPLACE 时可选返回自增 ID
   *
   * $master 为 true 或锁定读（FOR UPDATE / FOR SHARE 等各驱动锁定语法，
   * 见 Channel::isLockingQuery）时强制从写库（主库）获取连接：锁定读落
   * 从库将失去与主库写入者的互斥语义（与 think-orm 主库策略一致）。
   *
   * @param string|Raw $sql SQL 语句或 Raw 对象
   * @param array $bindings 参数绑定值
   * @param false|string $getId 需返回自增 ID 时传入序列名（false 表示不需要）
   * @param bool $master 是否强制走写库（主库）
   * @return PDOStatementProxy|PDOStatement|int|string 写语句且指定 getId 时
   *         返回自增 ID，其余返回预编译语句
   * @throws DbException SQL 执行失败时抛出
   */
  public function execute(
    string|Raw $sql,
    array $bindings = [],
    false|string $getId = false,
    bool $master = false
  ): PDOStatementProxy|PDOStatement|int|string {
    if ($sql instanceof Raw) {
      $bindings = $sql->bindings;
      $sql = $sql->sql;
    }
    $requestedRoute = $master || self::isLockingQuery($sql) ? 'write' : $this->getType($sql);
    // 未启用采集时直接执行，保留原有连接池路由选择，不在热路径做额外路由推断
    $trace = TraceContext::current();
    if ($trace === null || !$trace->options->captureDatabase) {
      return $this->executeSql($sql, $bindings, ['route' => $requestedRoute, 'get_id' => $getId]);
    }
    $route = $this->resolveExecutionRoute($requestedRoute);
    // 绑定值默认全部隐藏；开启 record_bindings 后仅对命名敏感参数脱敏，
    // 位置参数无法可靠识别用途，交由 PayloadSanitizer 统一处理
    // 仅复制有限个绑定，避免大 IN 查询在监控入口额外复制完整参数集合。
    $limitedBindings = array_slice($bindings, 0, 100, true);
    $recordedBindings = $trace->options->recordBindings ? $limitedBindings : array_fill_keys(array_keys($limitedBindings), '[REDACTED]');
    return TraceContext::measure([
      'type' => 'database', 'channel' => $this->name, 'route' => $route, 'requested_route' => $requestedRoute,
      'sql' => $sql, 'bindings' => $recordedBindings,
      'binding_count' => count($bindings), 'bindings_truncated' => count($bindings) > 100,
    ], fn (): PDOStatementProxy|PDOStatement|int|string => $this->executeSql(
      $sql,
      $bindings,
      ['route' => $requestedRoute, 'get_id' => $getId]
    ));
  }

  /**
   * 解析实际读写路由
   *
   * 事务连接与 sticky 都可能把普通 SELECT 送往写库，不能把语句类型当实际路由。
   *
   * @param string $requestedRoute 语句请求的连接类型
   * @return string 实际选择的读写路由
   */
  private function resolveExecutionRoute(string $requestedRoute): string
  {
    if (ConnectManager::factory()->transactionLevel() > 0) { return 'write'; }
    if (isset($this->pool) && is_array($this->pool) && Context::get($this->contextKey('$_pdo_write_pool_index_')) !== null) { return 'write'; }
    return $requestedRoute;
  }

  /**
   * 借出连接执行 SQL 并归还
   *
   * @param string $sql 参数化 SQL
   * @param array $bindings 绑定值
   * @param array $options 路由与 ID 选项（route、get_id）
   * @return PDOStatementProxy|PDOStatement|int|string 执行结果
   * @throws DbException SQL 执行失败时抛出
   */
  private function executeSql(string $sql, array $bindings, array $options): PDOStatementProxy|PDOStatement|int|string
  {
    $manager = ConnectManager::factory();
    $connect = $manager->pop($this, $options['route']);
    // 记录读写路由到协程上下文（按通道名隔离，协程间互不污染），
    // 供查询层构造 RunInfo 时读取，使监听器/日志能区分主从库
    Context::set('$_db_query_route_' . $this->name, $this->resolveExecutionRoute($options['route']));
    try {
      $statement = $this->executeStatement($connect, $sql, $bindings);
      // 大小写不敏感判定写语句类型：小写 SQL 同样需要返回自增 ID，
      // 否则 insertGetId 会静默退化为返回 statement
      $isInsertLike = stripos($sql, 'INSERT') === 0 || stripos($sql, 'REPLACE') === 0;
      if (!empty($options['get_id']) && $isInsertLike) { return $connect->lastInsertId($options['get_id']); }
      return $statement;
    } catch (PDOException $error) {
      // 抛出异常（errorInfo[1] 在部分驱动下可能未定义，回退到异常码）。
      // 异常携带占位符形式的 SQL 而非合并绑定值后的文本，防止 where 值
      // （如密码等敏感字段）随异常消息进入日志或响应造成信息泄露
      throw new DbException($error->getMessage(), $error->errorInfo[1] ?? $error->getCode(), $sql, $error);
    } finally {
      // 归还连接
      $manager->put($this, $connect);
    }
  }

  /**
   * 预编译并执行语句
   *
   * @param PDOProxy $connect 借出的连接
   * @param string $sql SQL
   * @param array $bindings 绑定值
   * @return PDOStatement|PDOStatementProxy 预编译语句
   */
  private function executeStatement(PDOProxy $connect, string $sql, array $bindings): PDOStatement|PDOStatementProxy
  {
    $statement = $connect->prepare($sql);
    if ($bindings === []) {
      $statement->execute();
    } else {
      // execute(array) 会将所有绑定值按 PARAM_STR 传递：布尔值在 MySQL
      // native prepare 下被序列化为空串、整型与 NULL 丧失原生语义，
      // 因此非空绑定时先按 PHP 类型显式声明绑定类型再执行
      $this->bindStatementValues($statement, $bindings);
      $statement->execute(null);
    }
    return $statement;
  }
}
