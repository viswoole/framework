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

namespace Viswoole\Cache\Contract;

/**
 * 原子缓存能力边界：NX 写入和 pull 必须原子执行，集合回收必须与注册注销原子执行。
 * 装饰器可以实现此接口，但必须按实际被包装驱动报告能力，不能提升其并发保证。
 */
interface AtomicCacheDriverInterface extends CacheDriverInterface
{
  /**
   * @return bool 当前底层驱动是否满足全部原子操作契约
   */
  public function supportsAtomicOperations(): bool;

  /**
   * 原子移除成员，并在集合为空时删除集合及其在注册集合中的记录。
   * @param string $key 集合键，同时也是注册集合中保存的成员值
   * @param string $registry 注册集合键
   * @param string|null $member 待移除成员；null 表示只回收空集合
   * @return void
   */
  public function pruneSet(string $key, string $registry, ?string $member = null): void;
}
