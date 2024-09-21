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

/**
 * 协程
 */
class Coroutine extends \Swoole\Coroutine
{
  /**
   * 获取当前协程id
   *
   * @return int
   */
  public static function id(): int
  {
    return static::getCid();
  }

  /**
   * 获取顶级协程id
   *
   * @param int|null $cid 协程cid，可传入某个协程的id以获取它的父id，默认值当前协程id。
   * @param bool $unableToFindReturnSelfId 如果没有父id是否返回当前协程id
   * @return false|int 如果返回false则没有上级协程id
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

    $pcid = static::getPcid($cid);
    //如果没有父id
    if ($pcid === false || $pcid === -1) return $unableToFindReturnSelfId ? $cid : false;
    // 递归调用，查找顶级协程
    return static::getTopId($pcid);
  }

  /**
   * 判断是否在协程中运行
   *
   * @access public
   * @return bool
   */
  public static function isCoroutine(): bool
  {
    return self::getCid() !== -1;
  }
}
