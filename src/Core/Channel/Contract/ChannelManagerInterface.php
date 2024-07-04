<?php
/*
 *  +----------------------------------------------------------------------
 *  | ViSwoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Core\Channel\Contract;

use Viswoole\Core\Exception\ChannelNotFoundException;

/**
 * 连接池通道管理接口
 */
interface ChannelManagerInterface
{
  /**
   * 获取通道
   *
   * @param string|null $channel_name 如果传入null则为获取默认通道连接池
   * @return ConnectionPoolInterface 返回连接池
   * @throws ChannelNotFoundException 通道不存在时抛出异常
   */
  public function getChannel(?string $channel_name = null): ConnectionPoolInterface;

  /**
   * 判断通道是否存在
   *
   * @param string $channel_name 不区分大小写的通道名称
   * @return bool
   */
  public function hasChannel(string $channel_name): bool;

  /**
   * 设置/更改默认通道
   *
   * @access public
   * @param string $channel_name 通道名称，不区分大小写
   * @return void
   * @throws ChannelNotFoundException 通道不存在时抛出异常
   */
  public function setDefaultChannel(string $channel_name): void;

  /**
   * 添加通道
   *
   * @param string $name 通道名称
   * @param ConnectionPoolInterface $channel 连接池
   * @return void
   */
  public function addChannel(string $name, ConnectionPoolInterface $channel): void;

  /**
   * 实现__call魔术方法，将方法调用转发到通道连接池
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public function __call(string $name, array $arguments);
}
