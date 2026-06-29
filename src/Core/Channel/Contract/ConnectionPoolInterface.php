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

namespace Viswoole\Core\Channel\Contract;

use RuntimeException;

/**
 * 连接池契约接口，定义连接的获取、归还与生命周期管理
 */
interface ConnectionPoolInterface
{
  /**
   * 获取连接的别名方法，等价于 pop()
   *
   * @see ConnectionPoolInterface::pop()
   */
  public function get(float $timeout = -1): mixed;

  /**
   * 从连接池中弹出（获取）一个连接
   *
   * @param float $timeout 超时时间（秒），-1 表示无限等待
   * @return mixed 连接对象
   * @throws RuntimeException 获取连接超时或失败时抛出
   */
  public function pop(float $timeout = -1): mixed;

  /**
   * 归还连接到连接池，连接不可用时请归还 null
   *
   * @param mixed $connection 连接对象，不可用时传入 null
   */
  public function put(mixed $connection): void;

  /**
   * 判断连接池是否为空（所有连接已被取出）
   *
   * @return bool 连接池为空返回 true，否则返回 false
   */
  public function isEmpty(): bool;

  /**
   * 关闭连接池，关闭后 get/put 操作将抛出异常
   *
   * @return bool 关闭成功返回 true
   */
  public function close(): bool;

  /**
   * 判断连接池是否已满
   *
   * @return bool 已满返回 true，否则返回 false
   */
  public function isFull(): bool;

  /**
   * 填充连接池至指定数量
   *
   * @param int|null $size 目标填充数量，必须大于当前连接池长度；为 null 时填充至池容量上限
   */
  public function fill(?int $size = null): void;

  /**
   * 获取连接池中当前可用的连接数量
   *
   * @return int 可用连接数
   */
  public function length(): int;

  /**
   * 获取连接池统计信息
   *
   * 返回的数组包含三个字段：
   *   1. consumer_num: 当前正在等待获取连接的消费者数量（连接池已空时出现）
   *   2. producer_num: 当前正在等待归还连接的生产者数量（连接池已满时出现）
   *   3. queue_num: 通道中的元素数，与 length() 返回值相同
   *
   * 示例：
   * [
   *   'consumer_num' => 0, // 目前没有调用 get() 方法
   *   'producer_num' => 1, // 连接池已满，有一个 put() 调用正在等待归还
   *   'queue_num'    => 2, // 连接池中有两个元素
   * ]
   *
   * @return array{consumer_num: int, producer_num: int, queue_num: int}
   */
  public function stats(): array;

  /**
   * 获取连接池配置信息
   *
   * @return mixed 连接池配置
   */
  public function getConfig(): mixed;
}
