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


abstract class AnnotationRouteAbstract
{
  /**
   * @param string|string[]|null $paths null则为当前方法名
   * @param string|array $methods 请求方法
   * @param string|null $describe 路由描述，null则会自动获取注释
   * @param array{middleware:array,suffix:string[],domain:string[],pattern:array,hidden:bool,describe:string} $options
   */
  public function __construct(
    public string|array|null $paths = null,
    public string|array      $methods = ['GET', 'POST'],
    public ?string           $describe = null,
    public array             $options = []
  )
  {
    $this->options['method'] = $this->methods;
    $this->options['describe'] = $this->describe ?? '';
  }
}
