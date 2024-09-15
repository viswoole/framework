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
 * Controller注解
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RouteController extends AnnotationRouteAbstract
{
  /**
   * @param array|string|null $prefix 前缀，null代表当前控制器类名称
   * @param string|array|null $method 请求方式
   * @param array $middleware 中间件
   * @param string|null $title 标题, 默认自动获取注释中的标题
   * @param string|null $server 服务器名称
   * @param array{description:string,suffix:string[],domain:string[],pattern:array<string,string>,hidden:bool,sort:int,meta:array<string,string>} $options 更多配置选项
   */
  public function __construct(
    array|string|null        $prefix = null,
    public string|array|null $method = null,
    array                    $middleware = [],
    public ?string           $title = null,
    public ?string           $server = null,
    array                    $options = []
  )
  {
    parent::__construct($prefix, $method, $middleware, $title, $options);
  }
}
