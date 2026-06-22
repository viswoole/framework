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

namespace Viswoole\Core;

use Override;
use Swoole\Coroutine\Context;

/**
 * 协程代理类
 *
 * 扩展 Swoole\Coroutine，添加非协程环境下的兼容性支持。
 * 在非协程环境中 getContext() 返回虚拟上下文对象，避免调用方判空。
 */
class Coroutine extends \Swoole\Coroutine
{
  /**
   * @var Context 非协程环境下的虚拟上下文对象，延迟创建
   */
  public static Context $mock_context;

  /**
   * 获取协程上下文对象，非协程环境下返回虚拟上下文以保持兼容
   *
   * @param int $cid 协程ID，0 或不传则使用当前协程
   * @return Context|null 协程上下文对象，非协程环境返回虚拟上下文
   */
  #[Override] public static function getContext(int $cid = 0): ?Context
  {
    if (!self::isCoroutine()) {
      return self::$mock_context ?? (self::$mock_context = new Context());
    } else {
      return parent::getContext($cid);
    }
  }

  /**
   * 判断当前是否在协程环境中运行（getCid() !== -1）
   *
   * @return bool 在协程中返回 true
   */
  public static function isCoroutine(): bool
  {
    return self::getCid() !== -1;
  }

  /**
   * 获取当前协程ID
   *
   * @return int 协程ID，非协程环境返回 -1
   */
  public static function id(): int
  {
    return static::getCid();
  }

  /**
   * 获取顶级（根）协程ID，沿协程树向上迭代查找无父协程的根节点
   *
   * @param int|null $cid 起始协程ID，默认为当前协程
   * @param bool $unableToFindReturnSelfId 无父协程时是否返回自身ID，为 false 则返回 false
   * @return false|int 顶级协程ID，非协程环境或 $unableToFindReturnSelfId 为 false 且无父协程时返回 false
   */
  public static function getTopId(
    ?int $cid = null,
    bool $unableToFindReturnSelfId = true
  ): false|int
  {
    if ($cid === null) {
      $cid = static::getCid();
      if ($cid === -1) return false;
    }
    // 修复#20: 递归改为迭代实现，避免深层协程栈溢出
    while (true) {
      $pcid = static::getPcid($cid);
      //如果没有父id
      if ($pcid === false || $pcid === -1) {
        return $unableToFindReturnSelfId ? $cid : false;
      }
      $cid = $pcid;
    }
  }
}
