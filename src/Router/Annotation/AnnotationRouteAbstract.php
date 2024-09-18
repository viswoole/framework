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


/**
 * 注解路由抽象类
 */
abstract class AnnotationRouteAbstract
{
  /**
   * @param string|string[]|null $paths null则为当前方法名
   * @param string|string[]|null $method 请求方式
   * @param array $middleware 中间件
   * @param string|null $title 路由标题，默认自动获取注释文档中第一行内容为标题
   * @param array{description:string,suffix:string[],domain:string[],pattern:array<string,string>,hidden:bool,sort:int,meta:array<string,string>} $options
   */
  public function __construct(
    public string|array|null $paths = null,
    public string|array|null $method = null,
    array                    $middleware = [],
    public ?string           $title = null,
    public array             $options = []
  )
  {
    if (!array_key_exists('middleware', $this->options)) {
      $this->options['middleware'] = $middleware;
    }
  }
}
