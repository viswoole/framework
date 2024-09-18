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

/**
 * 路由api文档相关配置
 */
trait ApiDoc
{
  /**
   * @var bool 隐藏路由
   */
  private bool $hidden = false;
  /**
   * @var string 标题
   */
  private string $title = '';
  /**
   * @var string 描述
   */
  private string $description = '';
  /**
   * @var int 排序，数值越大越靠前
   */
  private int $sort = 0;

  /**
   * 获取路由是否隐藏
   *
   * @return bool 如果隐藏返回true，否则返回false
   */
  public function getHidden(): bool
  {
    return $this->hidden;
  }

  /**
   * 应用路由是否隐藏
   *
   * @param bool $flag
   * @return $this
   */
  public function setHidden(bool $flag): static
  {
    $this->hidden = $flag;
    return $this;
  }

  /**
   * 获取排序值
   *
   * @return int 排序值
   */
  public function getSort(): int
  {
    return $this->sort;
  }

  /**
   * 设置排序值
   *
   * @param int $sort
   * @return $this
   */
  public function setSort(int $sort): static
  {
    $this->sort = $sort;
    return $this;
  }

  /**
   * 获取标题
   *
   * @return string 标题
   */
  public function getTitle(): string
  {
    return $this->title;
  }

  /**
   * 设置标题
   *
   * @param string $title
   * @return $this
   */
  public function setTitle(string $title): static
  {
    $this->title = $title;
    return $this;
  }

  /**
   * 获取描述
   *
   * @return string 描述
   */
  public function getDescription(): string
  {
    return $this->description;
  }

  /**
   * 设置描述
   *
   * @param string $description
   * @return $this
   */
  public function setDescription(string $description): static
  {
    $this->description = $description;
    return $this;
  }
}
