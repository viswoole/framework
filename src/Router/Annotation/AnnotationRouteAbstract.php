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
   * @param string|null $title 路由标题，null则会自动获取注释中的标题
   * @param string $description 路由描述，null则会自动获取注释
   * @param string|array|null $methods 请求方法
   * @param array{middleware:array,suffix:string[],domain:string[],pattern:array<string,string>,hidden:bool,sort:int,meta:array<string,string>} $options
   */
  public function __construct(
    public string|array|null $paths = null,
    public ?string           $title = null,
    public string            $description = '',
    public string|array|null $methods = null,
    public array             $options = []
  )
  {
    if (!empty($this->methods)) $this->options['method'] = $this->methods;
    $this->options['description'] = $this->description;
    if (!empty($this->title)) $this->options['title'] = $this->title;
  }
}
