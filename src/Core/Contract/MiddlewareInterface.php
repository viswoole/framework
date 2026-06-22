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

namespace Viswoole\Core\Contract;


use Closure;

/**
 * 中间件契约接口，所有中间件必须实现该方法以支持洋葱模型调度
 */
interface MiddlewareInterface
{
  /**
   * 执行中间件逻辑，通过调用 $handler 将控制权传递给下一个中间件
   *
   * @param Closure $handler 下一个中间件的处理闭包
   * @return mixed 中间件处理结果
   */
  public function process(
    Closure $handler
  ): mixed;
}
