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

use PDO;

/**
 * PDO配置
 */
class PDOConfig
{
  /**
   * @param DriverType $type 数据库类型
   * @param string $host 链接地址
   * @param int $port 端口
   * @param string|null $unixSocket unixSocket
   * @param string $database 数据库名称
   * @param string $username 用户名
   * @param string $password 密码
   * @param string $charset 数据库编码
   * @param array $options 其他配置
   * @param int $pool_max_size 连接池最大长度
   * @param int $pool_fill_size 连接池默认填充长度，默认0为不填充
   */
  public function __construct(
    public DriverType $type = DriverType::MYSQL,
    public string     $host = '127.0.0.1',
    public int        $port = 3306,
    public ?string    $unixSocket = null,
    public string     $database = 'test',
    public string     $username = 'root',
    public string     $password = 'root',
    public string     $charset = 'utf8mb4',
    public array      $options = [
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ],
    public int        $pool_max_size = 64,
    public int        $pool_fill_size = 0,
    public bool       $onlyRead = false
  )
  {
  }
}
