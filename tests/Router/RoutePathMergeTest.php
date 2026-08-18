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

namespace Viswoole\Tests\Router;

use PHPUnit\Framework\TestCase;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 父子路由路径合并规则测试
 *
 * 规则：子路由 path 不以 / 开头视为相对路径，与父级前缀拼接；
 * 以 / 开头视为绝对路径，忽略父级前缀直接作为根路由；
 * 单独的 / 仍表示父路由本身路径。
 * 配置 case_sensitive=false，断言路径统一为小写。
 */
class RoutePathMergeTest extends TestCase
{
  /**
   * 相对路径（不以/开头）应继承父级前缀拼接
   *
   * @return void
   */
  public function testRelativePathMergesParentPrefix(): void
  {
    $group = new Group('/Api', fn() => null, id: 'bench');
    $route = new Route('User', fn() => null, $group, id: 'user');

    $this->assertSame(['/api/user'], $route->getPaths());
  }

  /**
   * 绝对路径（以/开头）应忽略父级前缀，直接作为根路由
   *
   * @return void
   */
  public function testAbsolutePathIgnoresParentPrefix(): void
  {
    $group = new Group('/api', fn() => null, id: 'bench');
    $route = new Route('/User/Center', fn() => null, $group, id: 'user');

    $this->assertSame(['/user/center'], $route->getPaths());
  }

  /**
   * 单独的 / 应表示父路由本身路径（组默认入口语义保留）
   *
   * @return void
   */
  public function testSingleSlashEqualsParentPath(): void
  {
    $group = new Group('/api', fn() => null, id: 'bench');
    $route = new Route('/', fn() => null, $group, id: 'index');

    $this->assertSame(['/api'], $route->getPaths());
  }

  /**
   * 父级路径为根 / 时，相对子路径拼接不应产生双斜杠
   *
   * @return void
   */
  public function testParentRootPathJoinsWithoutDoubleSlash(): void
  {
    $group = new Group('/', fn() => null, id: 'bench');
    $route = new Route('user', fn() => null, $group, id: 'user');

    $this->assertSame(['/user'], $route->getPaths());
  }

  /**
   * 多路径混合声明时，绝对与相对路径应各自按规则合并
   *
   * @return void
   */
  public function testMixedPathsMergeIndependently(): void
  {
    $group = new Group('/api', fn() => null, id: 'bench');
    // list 顺序保证绝对路径在相对路径之后追加
    $route = new Route(['/user/Root', 'User'], fn() => null, $group, id: 'user');

    $this->assertSame(['/api/user', '/user/root'], $route->getPaths());
  }

  /**
   * 父级多路径与相对子路径应做笛卡尔积拼接
   *
   * @return void
   */
  public function testParentMultiPathsCartesianMerge(): void
  {
    $group = new Group(['/api', '/v2'], fn() => null, id: 'bench');
    $route = new Route('user', fn() => null, $group, id: 'user');

    $this->assertSame(['/api/user', '/v2/user'], $route->getPaths());
  }

  /**
   * 父级多路径时，绝对子路径不应随笛卡尔积重复
   *
   * @return void
   */
  public function testAbsolutePathNotDuplicatedByParentMultiPaths(): void
  {
    $group = new Group(['/api', '/v2'], fn() => null, id: 'bench');
    $route = new Route('/root', fn() => null, $group, id: 'root');

    $this->assertSame(['/root'], $route->getPaths());
  }

  /**
   * 动态参数路径同样遵循绝对/相对合并规则
   *
   * @return void
   */
  public function testDynamicVariablePathsMerge(): void
  {
    $group = new Group('/api', fn() => null, id: 'bench');
    $relative = new Route('user/{id}', fn() => null, $group, id: 'rel');
    $absolute = new Route('/user/{id}/profile', fn() => null, $group, id: 'abs');

    $this->assertSame(['/api/user/{id}'], $relative->getPaths());
    $this->assertSame(['/user/{id}/profile'], $absolute->getPaths());
  }

  /**
   * 嵌套分组：绝对子分组的路径不继承祖父级，其子路由仅继承该分组路径
   *
   * @return void
   */
  public function testNestedGroupAbsolutePathResetsPrefix(): void
  {
    $grandparent = new Group('/api', fn() => null, id: 'api');
    // /v2 为绝对路径，忽略父级 /api
    $parent = new Group('/v2', fn() => null, $grandparent, id: 'v2');
    $route = new Route('user', fn() => null, $parent, id: 'user');

    $this->assertSame(['/v2'], $parent->getPaths());
    $this->assertSame(['/v2/user'], $route->getPaths());
  }
}
