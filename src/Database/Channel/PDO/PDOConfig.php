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

use DateTimeZone;
use PDO;

/**
 * PDO 连接配置值对象
 *
 * 承载创建 PDO 连接所需的全部参数，同时包含连接池的容量配置。
 */
class PDOConfig
{
  /**
   * @param DriverType $type 数据库驱动类型
   * @param string $host 主机地址，使用 Unix Socket 时可为空
   * @param int $port 数据库端口
   * @param string|null $unixSocket Unix Socket 路径，设置后优先于 host 使用
   * @param string $database 数据库名称
   * @param string $username 用户名
   * @param string $password 密码
   * @param string $charset 字符集编码
   * @param string|null $timezone 连接时区：null 对齐应用时区，字符串使用指定时区，
   *                              空串不设置；仅 MySQL 与 PostgreSQL 生效（见 PDOPool）
   * @param array $options 额外 PDO 属性配置
   * @param int $pool_max_size 连接池最大连接数
   * @param int $pool_fill_size 连接池初始填充数，0 表示不预填充
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
    public ?string    $timezone = null,
    public array      $options = [
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ],
    public int        $pool_max_size = 10,
    public int        $pool_fill_size = 0
  )
  {
  }

  /**
   * 将时区转换为 MySQL 可用的 UTC 偏移格式
   *
   * MySQL 的 SET time_zone 对命名时区要求服务端加载时区表（不可靠），
   * 统一转换为 ±HH:MM 偏移注入。传入值本身是偏移时原样返回。
   *
   * @param string $timezone 命名时区（如 Asia/Shanghai）或偏移（如 +08:00）
   * @return string UTC 偏移格式（如 +08:00）
   */
  public static function toMysqlOffset(string $timezone): string
  {
    if (preg_match('/^[+-]\d{2}:\d{2}$/', $timezone)) return $timezone;
    $offset = new DateTimeZone($timezone)->getOffset(
      new \DateTimeImmutable('now', new DateTimeZone('UTC'))
    );
    $sign = $offset >= 0 ? '+' : '-';
    $minutes = abs($offset) / 60;
    return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
  }

  /**
   * 解析连接时区的实际生效值
   *
   * @return string|null 空串或无时区设置返回 null（不做注入）；
   *                     null 对齐应用时区；其余返回命名时区或 UTC 偏移字符串
   */
  public function resolveTimezone(): ?string
  {
    if ($this->timezone === '') return null;
    return $this->timezone ?? date_default_timezone_get();
  }
}
