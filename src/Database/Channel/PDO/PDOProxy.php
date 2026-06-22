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

namespace Viswoole\Database\Channel\PDO;

use AllowDynamicProperties;
use PDO;
use PDOStatement;
use Swoole\Database\PDOProxy as SwoolePDOProxy;
use Swoole\Database\PDOStatementProxy;

/**
 * PDO 协程代理
 *
 * 继承 Swoole 内置的 PDOProxy，在协程环境中自动管理底层 PDO 连接，
 * 避免每个协程独占连接导致资源浪费。
 *
 * @method bool beginTransaction() 启动事务
 * @method bool commit() 提交事务，未开启事务时抛出 PDOException
 * @method null|string errorCode() 获取最近操作的 SQLSTATE 错误码
 * @method array errorInfo() 获取扩展错误信息 [SQLSTATE, 驱动错误码, 错误消息]
 * @method false|int exec(string $statement) 执行 SQL 并返回受影响行数
 * @method mixed getAttribute(int $attribute) 获取连接属性值
 * @method array getAvailableDrivers() 获取可用 PDO 驱动列表
 * @method bool inTransaction() 检查是否处于事务中
 * @method false|string lastInsertId(?string $name = null) 获取最后插入行的 ID 或序列值
 * @method false|PDOStatementProxy|PDOStatement prepare(string $query, array $options = []) 预编译 SQL 语句
 * @method false|PDOStatementProxy|PDOStatement query(string $query, ?int $fetchMode = PDO::ATTR_DEFAULT_FETCH_MODE, mixed $fetchModeArgs = null) 执行 SQL 查询并返回语句对象
 * @method false|string quote(string $string, int $type = PDO::PARAM_STR) 转义 SQL 字符串
 * @method bool rollBack() 回滚事务，未启动事务时抛出 PDOException
 * @method bool setAttribute(int $attribute, mixed $value) 设置连接属性
 */
#[AllowDynamicProperties]
class PDOProxy extends SwoolePDOProxy
{
  /**
   * @param string $dsn 数据源名称（DSN）
   * @param string|null $username 用户名，SQLite 时可为 null
   * @param string|null $password 密码，SQLite 时可为 null
   * @param array|null $options PDO 连接属性配置
   */
  public function __construct(
    string  $dsn,
    ?string $username = null,
    ?string $password = null,
    ?array  $options = null,
  )
  {
    $constructor = function () use ($dsn, $username, $password, $options) {
      return new PDO($dsn, $username, $password, $options);
    };
    parent::__construct($constructor);
  }
}
