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

use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * ApiDoc解析工具
 */
class ApiDocParseTool
{
  /**
   * 解析路由信息
   *
   * @param array<Route|Group> $routes
   * @return array{
   *   count: int,
   *   routes: array<array>
   * }
   */
  public static function parse(array $routes): array
  {
    $count = 0;
    $list = [];
    foreach ($routes as $route) {
      if ($route instanceof Group) {
        $item = self::generateGroup($route);
        $count += $item['count'];
      } else {
        $count++;
        $item = self::generateRoute($route);
      }
      $list[] = $item;
    }
    return [
      'count' => $count,
      'routes' => $list,
    ];
  }

  /**
   * 生成路由组信息
   *
   * @param Group $group
   * @return array{
   *   type: string,
   *   id: string,
   *   parentId: string|null,
   *   citeLink: string|null,
   *   title: string,
   *   description: string,
   *   count: int,
   *   children: array<array>
   * }
   */
  private static function generateGroup(Group $group): array
  {
    $count = 0;
    $children = [];
    foreach ($group->getItem() as $child) {
      if ($child->getHidden()) continue;
      if ($child instanceof Group) {
        $subGroup = self::generateGroup($child);
        $children[] = $subGroup;
        $count += $subGroup['count'];
      } elseif ($child instanceof Route) {
        $count++;
        $children[] = self::generateRoute($child);
      }
    }
    return [
      'type' => 'group',
      'id' => $group->getId(),
      'parentId' => $group->getParentId(),
      'citeLink' => $group->getCiteLink(),
      'title' => $group->getTitle(),
      'description' => $group->getDescription(),
      'count' => $count,
      'children' => $children,
    ];
  }

  /**
   * 生成路由信息
   *
   * @param Route $route
   * @return array{
   *   type: string,
   *   id: string,
   *   parentId: string|null,
   *   citeLink: string,
   *   title: string,
   *   description: string,
   *   paths: string[],
   *   methods: string[],
   *   domains: string[],
   *   suffix: string,
   *   params: array<array>,
   *   tags: string[],
   *   createdAt: string,
   *   updatedAt: string,
   *   author: string,
   *   meta: array,
   *   status: array{
   *    id: string,
   *    label: string,
   *    color: string,
   *   },
   * }
   */
  private static function generateRoute(Route $route): array
  {
    return [
      'type' => 'route',
      'id' => $route->getId(),
      'parentId' => $route->getParentId(),
      'citeLink' => $route->getCiteLink(),
      'title' => $route->getTitle(),
      'description' => $route->getDescription(),
      'paths' => $route->getPaths(),
      'methods' => $route->getMethod(),
      'domains' => $route->getDomain(),
      'suffix' => $route->getSuffix(),
      'tags' => $route->getTags(),
      'createdAt' => $route->getCreatedAt(),
      'updatedAt' => $route->getUpdatedAt(),
      'author' => $route->getAuthor(),
      'meta' => $route->getMeta(),
      'status' => [
        'id' => $route->getStatus()->value,
        'label' => $route->getStatus()->getLabel(),
        'color' => $route->getStatus()->getColor()
      ]
    ];
  }
}
