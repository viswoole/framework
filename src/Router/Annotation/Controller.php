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
 * 控制器注解，将控制器类注册为路由组
 *
 * 继承 RouteAnnotation，type 为 group，仅注册带有 RouteMapping 注解的方法。
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Controller extends RouteAnnotation
{
  protected string $type = 'group';
}
