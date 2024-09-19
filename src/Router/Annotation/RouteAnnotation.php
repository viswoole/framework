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

use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由注解基类
 */
abstract class RouteAnnotation
{
  protected string $type;

  /**
   * 构建路由组
   *
   * @param string|array|null $prefix 路径匹配前缀，默认为控制器类名
   * @param string|null $id id
   * @param string|null $parentId 父id，必须是分组路由id
   * @param string[]|string|null $method 路由方法，默认继承全局设定的方法
   * @param array[]|null $middlewares 中间件
   * @param array<string,string>|null $patterns 动态路由正则约束
   * @param array|null $meta 路由元数据
   * @param array|null $suffix 目标后缀
   * @param array|null $domain 域名校验
   * @param bool $hidden 是否隐藏文档
   * @param string|null $title 路由标题
   * @param string|null $description 描述
   * @param int $sort 排序，数值越大越靠前
   */
  public function __construct(
    public string|array|null $prefix = null,
    public ?string           $id = null,
    public ?string           $parentId = null,
    public null|array|string $method = null,
    public ?array            $middlewares = null,
    public ?array            $patterns = null,
    public ?array            $meta = null,
    public ?array            $suffix = null,
    public ?array            $domain = null,
    public bool              $hidden = false,
    public ?string           $title = null,
    public ?string           $description = null,
    public int               $sort = 0,
  )
  {
    if (is_string($this->suffix)) {
      $this->suffix = [$this->suffix];
    }
    if (is_string($this->domain)) {
      $this->domain = [$this->domain];
    }
  }

  /**
   * 创建路由实例
   *
   * @param string|array|callable $handler
   * @param Group|null $routeGroup 路由组实例
   * @return Route|Group
   */
  public function create(
    string|array|callable $handler,
    Group                 $routeGroup = null
  ): Route|Group
  {
    if ($this->type === 'group') {
      $route = new Group($this->prefix, $handler, $routeGroup, id: $this->id);
    } else {
      $route = new Route($this->prefix, $handler, $routeGroup, id: $this->id);
    }
    if (!empty($this->parentId)) $route->setParentId($this->parentId);
    if (!empty($this->method)) $route->setMethod($this->method);
    if (!empty($this->middlewares)) $route->setMiddlewares($this->middlewares);
    if (!empty($this->patterns)) $route->setPatterns($this->patterns);
    if (!empty($this->meta)) $route->setMeta($this->meta);
    if (!empty($this->suffix)) $route->setSuffix(...$this->suffix);
    if (!empty($this->domain)) $route->setDomain(...$this->domain);
    $route->setHidden($this->hidden);
    $route->setSort($this->sort);
    $route->setDescription($this->description ?? '');
    $route->setTitle($this->title);
    return $route;
  }
}
