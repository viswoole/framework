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
use ReflectionProperty;
use RuntimeException;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由收集器分组存储回归测试
 *
 * 覆盖场景：顶层分组必须以 id 为键存储。若使用数字键，getRoute() 按引用链路
 * （如 bench.api.xxx）首段查找分组时无法命中，导致分组内注册动态参数路由时
 * （回查路由获取参数正则）抛出"路由链路引用错误"。
 */
class CollectorGroupTest extends TestCase
{
  /**
   * Collector 无抽象方法，通过匿名子类直接实例化以隔离测试 Collector 自身行为
   *
   * @return Collector
   */
  private function makeCollector(): Collector
  {
    return new class extends Collector
    {
    };
  }

  /**
   * 分组应以 id 为键存储，且可通过 id 直接查找
   *
   * @return void
   */
  public function testGroupStoredByIdKey(): void
  {
    $collector = $this->makeCollector();
    $group = $collector->group('/api', fn() => null, 'bench');

    $this->assertSame($group, $collector->getRoute('bench'));
    $this->assertArrayHasKey('bench', $collector->getRoutes());
  }

  /**
   * 重复的分组 id 应抛出异常（与 recordRouteItem() 的顶层路由行为一致）
   *
   * @return void
   */
  public function testDuplicateGroupIdThrowsException(): void
  {
    $collector = $this->makeCollector();
    $collector->group('/api', fn() => null, 'bench');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Route id:bench already exists');

    $collector->group('/v2', fn() => null, 'bench');
  }

  /**
   * 通过引用链路（bench.api）应能查找到分组内的子路由
   *
   * @return void
   */
  public function testGroupRouteCiteLinkLookup(): void
  {
    $collector = $this->makeCollector();
    $group = $collector->group('/bench', fn() => null, 'bench');
    // 模拟真实流程：子路由以分组为父级注册，并被收集到分组的 children 中
    $child = new Route('/user/{id}', fn() => null, $group, id: 'api');
    $group->addItem($child);

    $this->assertSame($child, $collector->getRoute('bench.api'));
  }

  /**
   * 嵌套分组应被添加到当前分组而非顶层 routes
   *
   * @return void
   */
  public function testNestedGroupAddedToCurrentGroup(): void
  {
    $collector = $this->makeCollector();
    $parent = $collector->group('/api', fn() => null, 'bench');
    // 模拟 register() 流程：解析分组闭包前设置 currentGroup
    $property = new ReflectionProperty(Collector::class, 'currentGroup');
    $property->setValue($collector, $parent);

    $child = $collector->group('/user', fn() => null, 'user');

    $this->assertSame($child, $parent->getItem('user'));
    // 嵌套分组不应进入顶层 routes
    $this->assertArrayNotHasKey('user', $collector->getRoutes());
  }
}
