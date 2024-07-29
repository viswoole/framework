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

namespace Viswoole\Database\Driver\PDO;

use AllowDynamicProperties;
use PDO;
use Swoole\Database\PDOProxy as SwoolePDOProxy;
use Swoole\Database\PDOStatementProxy;

/**
 * PDO代理
 *
 * @method bool beginTransaction() 启动事务
 * @method bool commit() 提交事务, 如果没有开启事务会抛出PDOException异常
 * @method null|string errorCode() 获取与语句句柄上的最后一个操作关联的 SQLSTATE
 * @method array errorInfo() 最后一个操作关联的扩展错误信, 实例：[0 => "SQLSTATE 字符串类型错误代码", 1 => "整数类型驱动程序错误码", 2 => "字符串类型错误消息"]
 * @method false|int exec(string $statement) 执行SQL语句，返回受影响的行
 * @method mixed getAttribute(int $attribute) 检索数据库连接属性, 失败返回null，成功返回属性值
 * @method array getAvailableDrivers() 返回 PDO 驱动程序名称的数组。如果没有可用的驱动程序，它将返回一个空数组。
 * @method bool inTransaction() 检查当前连接是否处于事务中
 * @method false|string lastInsertId(?string $name = null) 返回最后插入的行或序列值的 ID
 * @method false|PDOStatementProxy prepare(string $query, array $options) 准备要执行的语句并返回语句对象
 * @method false|PDOStatementProxy query(string $query, ?int $fetchMode = PDO::ATTR_DEFAULT_FETCH_MODE, mixed $fetchModeArgs = null) 执行 SQL 语句，返回作为 PDOStatement 对象的结果集
 * @method false|string quote(string $string, int $type = PDO::PARAM_STR) 引用用于查询的字符串
 * @method bool rollBack() 回归事务，成功时返回true，失败时返回false，未启动事务会抛出PDOException异常
 * @method bool setAttribute(int $attribute, mixed $value) 设置属性
 */
#[AllowDynamicProperties]
class PDOProxy extends SwoolePDOProxy
{
  public function __construct(
    string  $dsn,
    ?string $username = null,
    ?string $password = null,
    ?array  $options = null
  )
  {
    $constructor = function () use ($dsn, $username, $password, $options) {
      return new PDO($dsn, $username, $password, $options);
    };
    parent::__construct($constructor);
  }
}
