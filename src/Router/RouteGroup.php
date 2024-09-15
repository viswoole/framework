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

namespace Viswoole\Router;


use Closure;

/**
 * 分组路由
 */
class RouteGroup extends RouteConfig
{
  /**
   * @var RouteItem[]|RouteGroup[] 分组、域名路由存储items
   */
  protected array $items = [];

  /**
   * 往路由分组中追加一个路由
   *
   * @param RouteConfig $item
   * @return void
   */
  public function addItem(RouteConfig $item): void
  {
    $parent = $this->options['parent'];
    $id = $this->options['id'];
    if (empty($parent)) {
      $item->options['parent'] = $id;
    } else {
      $item->options['parent'] = "$parent.$id";
    }
    $this->items[$item->options['id']] = $item;
  }

  /**
   * 获取分组中的路由
   *
   * @param string $id
   * @return RouteGroup|RouteItem|null
   */
  public function getItem(string $id): RouteItem|RouteGroup|null
  {
    return $this->items[$id] ?? null;
  }

  /**
   * 注册路由（该方法由路由管理器自动调用，请勿手动调用）
   *
   * @param RouteCollector $collector
   * @return void
   * @deprecated 切勿外部调用
   */
  public function _register(RouteCollector $collector): void
  {
    if ($this->options['handler'] instanceof Closure) {
      $collector->currentGroup = $this;
      $this->options['handler']();
      $this->options['handler'] = null;
      $collector->currentGroup = null;
    }
    uasort($this->items, function (RouteConfig $a, RouteConfig $b) {
      return $b->sort <=> $a->sort;
    });
    foreach ($this->items as $item) {
      $item->_register($collector);
    }
  }

  /**
   * @inheritDoc
   */
  protected function handler(callable|array|string $handler): void
  {
    if (empty($handler)) return;
    parent::handler($handler);
  }
}
