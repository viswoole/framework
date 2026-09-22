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
 *
 * ⚠️ 实例复用为显式声明制（opt-in）：默认每请求实例化新实例；
 * 仅当类显式声明 `public const IS_STATELESS = true` 时，框架才进程级缓存
 * 复用实例（同一实例服务所有请求）。声明即承诺：
 * 1. 类完全无状态——不通过任何途径（实例属性、Facade 门面、全局函数等）
 *    保存或读取与单个请求相关的数据；
 * 2. 请求态数据一律经 $handler 链或协程上下文（Context）传递。
 * 反例：在 process 中经 Request 门面取当前请求参数的中间件禁止声明。
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
