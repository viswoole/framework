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

declare(strict_types=1);

namespace Viswoole\Router\Annotation;

use Attribute;
use Viswoole\Router\ApiDoc\Status;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由映射注解，用于方法级别定义单条路由及其文档元数据
 *
 * 支持设置路径、方法、中间件、标签、状态等，创建 Route 实例时自动写入文档信息。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class RouteMapping extends RouteAnnotation
{
  protected string $type = 'item';

  /**
   * 构建路由项
   *
   * @param array|string|null $paths 路径匹配前缀，默认为方法名
   * @param string|null $id id
   * @param string|null $parentId 父id，必须是分组路由id
   * @param string[]|string|null $method 路由方法，默认继承全局设定的方法
   * @param array|null $middlewares 中间件列表，支持类名、[类名, 构造参数数组] 格式（注解参数受常量表达式限制，不支持闭包），见 Middleware::checkMiddleware()
   * @param array<string,string>|null $patterns 动态路由正则约束
   * @param array|null $meta 路由元数据
   * @param array|string|null $suffix 目标后缀，字符串或字符串数组
   * @param array|string|null $domain 域名校验，字符串或字符串数组
   * @param bool $hidden 是否隐藏文档
   * @param string|null $title 路由标题
   * @param string|null $description 说明
   * @param int $sort 排序，数值越大越靠前
   * @param string $author 作者
   * @param string $createdAt 创建时间
   * @param string $updatedAt 更新时间
   * @param string[] $tags 标签
   * @param Status $status 接口状态，默认为 Status::DEVELOPMENT（开发中）
   */
  public function __construct(
    array|string|null $paths = null,
    ?string           $id = null,
    ?string           $parentId = null,
    null|array|string $method = null,
    ?array            $middlewares = null,
    ?array            $patterns = null,
    ?array            $meta = null,
    null|string|array $suffix = null,
    null|string|array $domain = null,
    bool              $hidden = false,
    ?string           $title = '',
    ?string           $description = '',
    int               $sort = 0,
    public string     $author = '',
    public string     $createdAt = '',
    public string     $updatedAt = '',
    public array      $tags = [],
    public Status     $status = Status::DEVELOPMENT,
  ) {
    parent::__construct(
      prefix: $paths,
      id: $id,
      parentId: $parentId,
      method: $method,
      middlewares: $middlewares,
      patterns: $patterns,
      meta: $meta,
      suffix: $suffix,
      domain: $domain,
      hidden: $hidden,
      title: $title,
      description: $description,
      sort: $sort
    );
  }

  /**
   * @inheritDoc
   */
  public function create(string|array|callable $handler, ?Group $routeGroup = null): Route
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
