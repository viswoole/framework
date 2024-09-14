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

namespace Viswoole\Router\ApiDoc\Structure;

use JsonSerializable;

/**
 * 结构声明基类
 */
abstract class BaseStructure implements JsonSerializable
{
  /**
   * 转换为数组
   *
   * @param bool $recursion 递归
   * @return array
   */
  public function toArray(bool $recursion = true): array
  {
    if (!$recursion) {
      return $this->jsonSerialize();
    } else {
      return json_decode($this->__toString(), $recursion);
    }
  }

  /**
   * 转换为字符串
   *
   * @return string json字符串
   */
  public function __toString(): string
  {
    return json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
}
