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

namespace Viswoole\Router\ApiDoc;

/**
 * 路由=>接口文档
 */
class Item
{
  /**
   * @param string|null $parent 父级引用链
   * @param string $id 路由id
   * @param string $title 路由名称
   * @param string $description 路由描述
   * @param array $paths 路由路径,支持多个
   * @param array $method 请求方式
   * @param string $author 作者
   * @param bool $deprecated 弃用
   */
  public function __construct(
    public ?string $parent,
    public string  $id,
    public string  $title,
    public string  $description,
    public array   $paths,
    public array   $method,
    public string  $author,
    public bool    $deprecated,
  )
  {
  }
}
