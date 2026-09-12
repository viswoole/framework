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

use Throwable;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * API 文档解析工具，将路由树转换为文档结构
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
      try {
        if ($route instanceof Group) {
          $item = self::generateGroup($route);
          $count += $item['count'];
        } else {
          $count++;
          $item = self::generateRoute($route);
        }
        $list[] = $item;
      } catch (Throwable $e) {
        // 解析单条路由失败时记录错误（含路由标识与异常类名，便于定位问题路由），
        // 继续解析其他路由
        $routeLabel = $route instanceof Route ? $route->getCiteLink() : $route->getId();
        trigger_error(
          'ApiDoc解析路由失败（' . $routeLabel . '）: '
          . get_class($e) . ': ' . $e->getMessage(),
          E_USER_WARNING
        );
      }
    }
    return [
      'count' => $count,
      'routes' => $list,
    ];
  }

  /**
   * 递归生成路由组的文档结构
   *
   * @param Group $group 路由组
   * @return array{
   *    type: string,
   *    id: string,
   *    parentId: string|null,
   *    citeLink: string|null,
   *    title: string,
   *    description: string,
   *    source: array{file:string, line:int}|null,
   *    count: int,
   *    children: array<array>
   *  } 分组文档结构
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
      'source' => $group->getSource(),
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
   *   source: array{file:string, line:int}|null,
   *   paths: string[],
   *   methods: string[],
   *   domains: string[],
   *   suffix: string[],
   *   params: array<array>,
   *   tags: string[],
   *   createdAt: string,
   *   updatedAt: string,
   *   author: string,
   *   meta: array,
   *   status: array{
   *    value: string,
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
      'source' => $route->getSource(),
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
        'value' => $route->getStatus()->value,
        'label' => $route->getStatus()->getLabel(),
        'color' => $route->getStatus()->getColor()
      ]
    ];
  }
}
