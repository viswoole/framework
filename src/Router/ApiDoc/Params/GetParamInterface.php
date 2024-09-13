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

namespace Viswoole\Router\ApiDoc\Params;

use Viswoole\Core\Contract\PreInjectInterface;

/**
 * 参数需带有实现了该接口的注解声明，文档才能解析参数类型为get请求查询参数。
 *
 * 使用路由的服务应该实现该接口，并在inject方法中注入对应的查询参数。
 */
interface GetParamInterface extends PreInjectInterface
{

}
