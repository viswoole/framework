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

use RuntimeException;

interface ConnectionPoolInterface
{
  /**
   * 该方法为pop方法的别名方法
   *
   * @see ConnectionPoolInterface::pop()
   */
  public function get(float $timeout = -1): mixed;

  /**
   * 从连接池中获取一个连接
   *
   * @param float $timeout 超时时间
   * @return mixed
   * @throws RuntimeException 如果获取连接失败则会抛出异常
   */
  public function pop(float $timeout = -1): mixed;

  /**
   * 归还一个连接到连接池中（必须实现）
   *
   * @param mixed $connection 连接对象（不可用时请归还null）
   * @return void
   */
  public function put(mixed $connection): void;

  /**
   * 判断连接池中连接是否已经被取完或者为空
   *
   * @return bool
   */
  public function isEmpty(): bool;

  /**
   * 关闭连接池
   *
   * 关闭连接池后的行为：
   *   1. static::get()方法会抛出异常。
   *   2. static::put()方法将会抛出异常。
   *   3. 不能再将连接推入其中，也无法从连接池中弹出连接
   *
   * @return bool
   */
  public function close(): bool;

  /**
   * 判断当前连接池是否已满
   *
   * @access public
   * @return bool
   */
  public function isFull(): bool;

  /**
   * 填充连接
   *
   * @access public
   * @param int|null $size 需要填充的数量(传入的值必须大于当前连接池长度)
   * @return void
   */
  public function fill(int $size = null): void;

  /**
   * 获取连接池中当前剩余连接数量
   *
   * @return int
   */
  public function length(): int;

  /**
   * 获取连接池统计信息
   *
   * 返回的数组包含三个字段：
   *   1. consumer_num: 当前static::get()方法正在等待从连接池中获取连接的数量，当连接池已空时会出现。
   *   2. producer_num: 当前static::put()方法正在等待归还到连接池中的数量。当连接池已满时，就会发生这种情况。
   *   3. queue_num: 通道中的元素数。这与语句static::length()的返回值相同。
   *
   *  For example:
   *  [
   *    'consumer_num' => 0, // 目前没有调用get()方法.
   *    'producer_num' => 1, // 连接池已满，并且有一个对put()的方法调用正在等待归还。
   *    'queue_num'    => 2, // 连接池中有两个元素。在这种情况下，连接池的大小也是两个。
   *  ]
   * @return array{
   *   consumer_num: int,
   *   producer_num: int,
   *   queue_num: int,
   * }
   */
  public function stats(): array;

  /**
   * @return mixed 获取连接池配置
   */
  public function getConfig(): mixed;
}
