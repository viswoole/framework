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

namespace Viswoole\Router\Route;

use Closure;

/**
 * 路由组
 */
class Group extends BaseRoute
{
  protected string $type = 'group';
  /**
   * @var Route[]|static[] 分组、域名路由存储items
   */
  private array $children = [];

  /**
   * 获取子路由
   *
   * @param string|null $id 路由id,不传时返回所有子路由
   * @return array|Route|Group|null
   */
  public function getItem(string $id = null): null|array|Route|Group
  {
    if ($this->handler instanceof Closure) {
      call_user_func($this->handler);
      $this->handler = null;
      uasort($this->children, function (BaseRoute $a, BaseRoute $b) {
        return $b->getSort() <=> $a->getSort();
      });
    }
    if (empty($id)) {
      return $this->children;
    } else {
      return $this->children[$id] ?? null;
    }
  }

  /**
   * 往路由分组中追加一个路由
   *
   * @param BaseRoute $item
   * @return void
   */
  public function addItem(BaseRoute $item): void
  {
    $this->children[$item->getId()] = $item;
  }

  /**
   * 设置父级路由ID
   *
   * @param ?string $parentId
   * @return $this
   */
  public function setParentId(?string $parentId): static
  {
    parent::setParentId($parentId);
    foreach ($this->children as $item) {
      $item->setParentId($this->getCiteLink());
    }
    return $this;
  }

  /**
   * @inheritDoc
   */
  protected function verifyHandler(callable|array|string $handler): callable|array
  {
    if (empty($handler)) return [];
    return parent::verifyHandler($handler);
  }
}
