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

namespace Viswoole\Router\Annotation;

use Attribute;

/**
 * 自动控制器注解，自动注册控制器下所有公共方法为路由
 *
 * 继承 Controller，无需为每个方法单独添加 RouteMapping 注解。
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AutoController extends Controller
{
}
