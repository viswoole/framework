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

namespace Viswoole\Database;


use PDO;
use PDOStatement;
use Swoole\Database\PDOStatementProxy;
use Viswoole\Database\Exception\DbException;
use Viswoole\Database\Query\Options;

/**
 * 数据库通道抽象基类
 *
 * 定义数据库通道的标准接口：查询构建、SQL执行、连接获取与归还。
 * 具体实现（如 PDOChannel）负责连接池管理和驱动适配。
 *
 * @see \Viswoole\Database\Channel\PDO\PDOChannel
 */
abstract class Channel
{
  /**
   * 查询语句的首关键字列表（以这些关键字开头的 SQL 视为查询语句）
   */
  private const array QUERY_STATEMENTS = [
    'SELECT',
    'SHOW',
    'EXPLAIN',
    'DESCRIBE',
    'DESC',
    'WITH',
    'PRAGMA',
  ];

  /**
   * 创建指定表的查询构建器
   *
   * @param string $table 表名
   * @param string $pk 主键字段名，默认 'id'
   * @return BaseQuery 查询构建器实例
   */
  public function table(string $table, string $pk = 'id'): BaseQuery
  {
    return new BaseQuery($this, $table, $pk);
  }

  /**
   * 执行原生 SELECT 查询并返回结果集
   *
   * 用于执行手写 SQL（如多表 join、子查询等），返回关联数组格式的结果集。
   * 该方法是 execute 的 SELECT 语义封装：PDO 通道自动拉取所有行并释放游标，
   * 其他通道若 execute 直接返回数组则原样返回。
   * 与 think-orm 一致：读写分离场景下默认从读库执行，$master=true 时强制从主库读取。
   *
   * @param string|Raw $sql SQL语句或 Raw 对象
   * @param array $bindings 绑定参数，与 SQL 中的占位符对应
   * @param bool $master 是否强制从主库（写库）读取
   * @return array 查询结果数组，每个元素为一行关联数组
   * @throws DbException 传入写入语句或 execute 返回类型不支持时抛出
   */
  public function query(string|Raw $sql, array $bindings = [], bool $master = false): array
  {
    // 执行前校验语句类型：query() 仅接受查询语句，写入语句在到达数据库前即抛出明确错误（fail-fast）
    $statement = $sql instanceof Raw ? $sql->sql : $sql;
    if (!self::isQueryStatement($statement)) {
      throw new DbException(
        'query() 仅用于执行查询语句（SELECT/SHOW/EXPLAIN 等），当前为写入语句，请改用 execute()'
      );
    }
    $result = $this->execute($sql, $bindings, false, $master);
    if ($result instanceof PDOStatement || $result instanceof PDOStatementProxy) {
      $rows = $result->fetchAll(PDO::FETCH_ASSOC);
      $result->closeCursor();
      return $rows;
    }
    throw new DbException('查询语句未返回结果集，execute() 返回类型为 ' . get_debug_type($result));
  }

  /**
   * 判断 SQL 语句是否为查询语句（SELECT/SHOW/EXPLAIN 等）
   *
   * 仅依据语句的首个关键字判断，用于 query() 执行前的语义校验与读写路由。
   * 注意：这是尽力而为的守卫，不处理注释、括号前缀等边界情况，也不构成安全边界。
   *
   * @param string $sql 待判断的 SQL 语句
   * @return bool 是查询语句返回 true，否则返回 false
   */
  public static function isQueryStatement(string $sql): bool
  {
    $first = strtoupper(preg_split('/\s+/', trim($sql))[0] ?? '');
    return in_array($first, self::QUERY_STATEMENTS, true);
  }

  /**
   * 执行SQL语句
   *
   * 契约约定：查询语句返回 PDOStatement（结果集），写入语句返回 PDOStatement（需取 rowCount）
   * 或自增 ID（传入 $getId 时返回 int|string）。各通道实现必须遵守该返回契约，
   * 上层 query()/execute() 方可保证稳定返回类型。
   *
   * @param string|Raw $sql SQL语句或 Raw 对象
   * @param array $bindings 绑定参数
   * @param false|string $getId 传入字段名时返回该字段的自增ID，false 时不获取
   * @param bool $master 是否强制从主库（写库）执行，读写分离场景下生效
   * @return PDOStatementProxy|PDOStatement|int|string PDOStatement 或受影响行数/自增ID
   * @throws DbException SQL执行失败时抛出
   */
  abstract public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): PDOStatementProxy|PDOStatement|int|string;

  /**
   * 从连接池获取一个可用连接
   *
   * @param string $type 连接类型 read|write
   * @return mixed 数据库连接实例
   */
  abstract public function pop(string $type): mixed;

  /**
   * 归还连接到连接池，连接损坏时归还 null
   *
   * @param mixed $connect 数据库连接实例
   */
  abstract public function put(mixed $connect): void;

  /**
   * 根据查询选项构建SQL语句
   *
   * @param Options $options 查询选项
   * @return Raw 构建后的SQL表达式
   */
  abstract public function build(Options $options): Raw;
}
