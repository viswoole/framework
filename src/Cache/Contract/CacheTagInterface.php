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

namespace Viswoole\Cache\Contract;

use DateTime;

/**
 * 缓存标签接口，定义按标签分组管理缓存的操作契约
 *
 * 标签将多个缓存键归入同一逻辑分组，支持按组写入、清除和查询。
 */
interface CacheTagInterface
{
  /**
   * 初始化标签实例
   *
   * @param string|array $tags 一个或多个标签名
   * @param CacheDriverInterface $driver 缓存驱动实例
   */
  public function __construct(string|array $tags, CacheDriverInterface $driver);

  /**
   * 写入缓存值并自动将其键追加到当前标签集合中
   *
   * @param string $key 缓存键
   * @param mixed $value 缓存值
   * @param DateTime|int|null $expire 过期时间（秒），null 使用驱动默认值
   * @param bool $NX 是否仅在缓存不存在时写入
   * @return bool 写入成功返回 true
   */
  public function set(string $key, mixed $value, DateTime|int|null $expire = null, bool $NX = false
  ): bool;

  /**
   * 清除所有标签下的缓存数据及标签本身
   */
  public function clear(): void;

  /**
   * 从标签集合中移除指定缓存键并删除对应缓存数据
   *
   * 若标签集合为空，则同时从标签仓库中移除该标签。
   *
   * @param string|array $keys 要移除的缓存键或键列表
   */
  public function remove(string|array $keys): void;

  /**
   * 将缓存键追加到所有当前标签的集合中，并注册标签到标签仓库
   *
   * @param string $key 缓存键
   */
  public function push(string $key): void;

  /**
   * 获取标签下的所有缓存键
   *
   * 单标签时返回扁平键列表 [key1, key2, ...]，
   * 多标签时返回按标签分组的映射 [tag1 => [key1, ...], tag2 => [key2, ...]]。
   *
   * @return array 缓存键列表或标签-键映射
   */
  public function get(): array;
}
