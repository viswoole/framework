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

namespace Viswoole\Core\Channel;

use BadMethodCallException;
use Override;
use Swoole\Coroutine\WaitGroup;
use Viswoole\Core\Channel\Contract\ChannelManagerInterface;
use Viswoole\Core\Channel\Contract\ConnectionPoolInterface;
use Viswoole\Core\Common\Str;
use Viswoole\Core\Exception\ChannelNotFoundException;
use function Swoole\Coroutine\run;

/**
 * 连接池通道管理基类
 *
 * 管理多个命名的连接池实例，支持默认通道切换和代理调用。
 * 通道名称统一转为蛇形命名存储。
 *
 * @method mixed get(float $timeout = -1) 从默认连接池中获取一个连接
 * @method void put(mixed $connection) 归还一个连接到默认连接池
 * @method bool isEmpty() 判断默认连接池是否为空
 * @method bool close() 关闭默认连接池
 * @method bool isFull() 判断默认连接池是否已满
 * @method void fill(int $size = null) 填充默认连接池
 * @method int length() 获取默认连接池中当前连接数量
 * @method array stats() 获取默认连接池统计信息
 */
abstract class ChannelManager implements ChannelManagerInterface
{
  /**
   * @var array<string,ConnectionPoolInterface> 已注册的连接池实例，键为蛇形命名的通道名
   */
  protected array $channels = [];
  /**
   * @var string 默认连接池名称
   */
  protected string $defaultChannel;

  /**
   * @param array $channels 通道配置，键为通道名，值为连接池配置
   * @param string $defaultChannel 默认通道名称
   */
  public function __construct(
    array  $channels,
    string $defaultChannel
  )
  {
    $this->defaultChannel = $defaultChannel;
    // 创建协程容器, 在其内部处理所有通道的创建
    run(function () use ($channels) {
      $wg = new WaitGroup();
      foreach ($channels as $name => $config) {
        $wg->add();
        go(function () use ($name, $config, $wg) {
          $channel = $this->createPool($config);
          $this->addChannel(Str::camelCaseToSnakeCase($name), $channel);
          $wg->done();
        });
      }
      $wg->wait();
    });
  }

  /**
   * 根据配置创建连接池实例，子类必须实现
   *
   * @param mixed $config 连接池配置
   * @return ConnectionPoolInterface 创建的连接池实例
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
      // 修复: 移除硬编码的 "redis"，使错误信息通用化
      "通道{$channel_name}不存在"
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
    // 修复: 检查接口 ConnectionPoolInterface 而非具体实现类 ConnectionPool，提高扩展性
    if (method_exists(ConnectionPoolInterface::class, $name)) {
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
    if (empty($channel_name)) $channel_name = $this->defaultChannel;
    if ($this->hasChannel($channel_name)) {
      return $this->channels[Str::camelCaseToSnakeCase($channel_name)];
    } else {
      throw new ChannelNotFoundException("通道{$channel_name}不存在");
    }
  }
}
