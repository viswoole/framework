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

namespace Viswoole\Core\Channel\Contract;

use Viswoole\Core\Exception\ChannelNotFoundException;

/**
 * 通道管理契约接口，提供命名连接池的注册、获取与默认通道管理
 */
interface ChannelManagerInterface
{
  /**
   * 获取指定名称的连接池，传入 null 时返回默认连接池
   *
   * @param string|null $channel_name 通道名称，为 null 时获取默认通道
   * @return ConnectionPoolInterface 连接池实例
   * @throws ChannelNotFoundException 通道不存在时抛出
   */
  public function getChannel(?string $channel_name = null): ConnectionPoolInterface;

  /**
   * 判断指定名称的通道是否已注册
   *
   * @param string $channel_name 通道名称，不区分大小写
   * @return bool 已注册返回 true，否则返回 false
   */
  public function hasChannel(string $channel_name): bool;

  /**
   * 设置默认通道，后续 getChannel(null) 将返回该通道
   *
   * @param string $channel_name 通道名称，不区分大小写
   * @throws ChannelNotFoundException 通道不存在时抛出
   */
  public function setDefaultChannel(string $channel_name): void;

  /**
   * 注册一个命名连接池通道
   *
   * @param string $name 通道名称
   * @param ConnectionPoolInterface $channel 连接池实例
   */
  public function addChannel(string $name, ConnectionPoolInterface $channel): void;

  /**
   * 魔术方法代理，将方法调用转发到默认通道连接池
   *
   * @param string $name 方法名
   * @param array $arguments 方法参数
   * @return mixed 连接池方法的返回值
   */
  public function __call(string $name, array $arguments);
}
