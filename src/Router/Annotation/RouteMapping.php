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
use Viswoole\Router\ApiDoc\Status;
use Viswoole\Router\Route\RouteGroup;
use Viswoole\Router\Route\RouteItem;

/**
 * 路由线路
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RouteMapping extends RouteAnnotation
{
  protected string $type = 'item';

  /**
   * 构建路由线路
   *
   * @param array|string $paths 路径匹配前缀，默认为控制器类名
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
   * @param string|null $description 说明
   * @param int $sort 排序，数值越大越靠前
   * @param string $author 作者
   * @param string $createdAt 创建时间
   * @param string $updatedAt 更新时间
   * @param array $tags 标签
   * @param Status $status 接口状态，默认为Viswoole\Router\ApiDoc\Status::DEVELOPMENT 开发中
   */
  public function __construct(
    array|string      $paths,
    ?string           $id = null,
    ?string           $parentId = null,
    null|array|string $method = null,
    ?array            $middlewares = null,
    ?array            $patterns = null,
    ?array            $meta = null,
    ?array            $suffix = null,
    ?array            $domain = null,
    bool              $hidden = false,
    ?string           $title = '',
    ?string           $description = '',
    int               $sort = 0,
    public string     $author = '',
    public string     $createdAt = '',
    public string     $updatedAt = '',
    public array      $tags = [],
    public Status     $status = Status::DEVELOPMENT,
  )
  {
    parent::__construct(
      prefix     : $paths,
      id         : $id,
      parentId   : $parentId,
      method     : $method,
      middlewares: $middlewares,
      patterns   : $patterns,
      meta       : $meta,
      suffix     : $suffix,
      domain     : $domain,
      hidden     : $hidden,
      title      : $title,
      description: $description,
      sort       : $sort
    );
  }

  /**
   * @inheritDoc
   */
  public function create(string|array|callable $handler, RouteGroup $routeGroup = null): RouteItem
  {
    $route = parent::create($handler, $routeGroup);
    $route->setAuthor($this->author);
    $route->setCreatedAt($this->createdAt);
    $route->setUpdatedAt($this->updatedAt);
    $route->setTags(...$this->tags);
    $route->setStatus($this->status);
    return $route;
  }
}
