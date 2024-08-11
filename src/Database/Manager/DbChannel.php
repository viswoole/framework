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

namespace Viswoole\Database\Manager;

use InvalidArgumentException;
use Viswoole\Core\Common\Str;
use Viswoole\Core\Config;
use Viswoole\Database\Driver\Contract\ChannelInterface;
use Viswoole\Database\Exception\DbException;

/**
 * 数据库通道管理器
 */
class DbChannel
{
  /**
   * @var array<string,ChannelInterface> 数据库通道
   */
  protected array $channels = [];

  protected string $defaultChannel;

  /**
   * @param Config $config
   */
  public function __construct(protected Config $config)
  {
    $channels = $config->get('database.channel', []);
    if (!empty($channels)) {
      $this->defaultChannel = $config->get('database.default', array_key_first($channels));
      foreach ($channels as $key => $driver) {
        if (!$driver instanceof ChannelInterface) {
          throw new DbException(
            '数据库通道 ' . $key . ' 驱动类需实现' . ChannelInterface::class . '接口'
          );
        }
        $this->channels[Str::camelCaseToSnakeCase($key)] = $driver;
      }
    }
  }

  /**
   * 获取数据库通道
   *
   * @access public
   * @param string|null $name 通道名称
   * @return ChannelInterface
   */
  public function channel(string $name = null): ChannelInterface
  {
    $name = $name ?? $this->defaultChannel;
    if (!$this->hasChannel($name)) {
      throw new InvalidArgumentException('数据库通道 ' . $name . ' 不存在');
    }
    return $this->channels[$name];
  }

  /**
   * 判断通道是否存在
   *
   * @access public
   * @param string $channel_name
   * @return bool
   */
  public function hasChannel(string $channel_name): bool
  {
    return isset($this->channels[Str::camelCaseToSnakeCase($channel_name)]);
  }
}
