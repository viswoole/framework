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

namespace Viswoole\Core\Channel;

use BadMethodCallException;
use Override;
use ViSwoole\Core\Channel\Contract\ChannelManagerInterface;
use ViSwoole\Core\Channel\Contract\ConnectionPoolInterface;
use ViSwoole\Core\Common\Str;
use ViSwoole\Core\Exception\ChannelNotFoundException;
use function Swoole\Coroutine\run;

/**
 * 连接池通道管理基类
 *
 * @method mixed get(float $timeout = -1) 从连接池中获取一个连接
 * @method void put(mixed $connection) 归还一个连接到连接池中（必须实现）
 * @method bool isEmpty() 判断连接池中连接是否已经被取完或者为空
 * @method bool close() 关闭连接池
 * @method bool isFull() 判断当前连接池是否已满
 * @method void fill(int $size = null) 填充连接
 * @method int length() 获取连接池中当前剩余连接数量
 * @method array stats() 获取连接池统计信息
 */
abstract class ChannelManager implements ChannelManagerInterface
{
  /**
   * @var array{string:ConnectionPoolInterface}
   */
  protected array $channels = [];
  /**
   * @var string 默认连接池
   */
  protected string $defaultChannel;

  /**
   * @param array $channels 通道名称
   * @param string $defaultChannel
   */
  public function __construct(
    array  $channels,
    string $defaultChannel
  )
  {
    $this->defaultChannel = $defaultChannel;
    foreach ($channels as $name => $config) {
      run(function () use ($name, $config) {
        $connect = $this->createPool($config);
        $this->addChannel(Str::camelCaseToSnakeCase($name), $connect);
      });
    }
  }

  /**
   * 创建连接池
   *
   * @param mixed $config 配置
   * @return ConnectionPoolInterface
   */
  abstract protected function createPool(mixed $config): ConnectionPoolInterface;

  /**
   * @inheritDoc
   */
  #[Override] public function addChannel(string $name, ConnectionPoolInterface $channel): void
  {
    $this->channels[Str::camelCaseToSnakeCase($name)] = $channel;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function setDefaultChannel(string $channel_name): void
  {
    if (!$this->hasChannel($channel_name)) throw new ChannelNotFoundException(
      "redis通道{$channel_name}不存在"
    );
    $this->defaultChannel = $channel_name;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function hasChannel(string $channel_name): bool
  {
    return isset($this->channels[Str::camelCaseToSnakeCase($channel_name)]);
  }

  /**
   * @inheritDoc
   */
  public function __call(string $name, array $arguments)
  {
    if (method_exists(ConnectionPool::class, $name)) {
      return call_user_func_array([$this->getChannel(), $name], $arguments);
    } else {
      throw new BadMethodCallException("方法{$name}不存在");
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getChannel(?string $channel_name = null): ConnectionPoolInterface
  {
    if (empty($this->channels)) throw new ChannelNotFoundException('通道列表为空');
    if (empty($channel_alias)) $channel_alias = $this->defaultChannel;
    if ($this->hasChannel($channel_alias)) {
      return $this->channels[Str::camelCaseToSnakeCase($channel_alias)];
    } else {
      throw new ChannelNotFoundException("通道{$channel_alias}不存在");
    }
  }
}
