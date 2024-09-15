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

namespace Viswoole\Router\ApiDoc\Structure;

/**
 * 数组结构描述
 */
class ArrayTypeStructure extends BaseTypeStructure
{
  /**
   * @var array<string,BaseTypeStructure> 数组元素结构
   */
  public array $items = [];

  /**
   * 构建数组结构
   *
   * @param BaseTypeStructure ...$items 数组元素结构
   */
  public function __construct(BaseTypeStructure ...$items)
  {
    parent::__construct('array');
    $this->addItem(...$items);
  }

  /**
   * 追加数组元素结构
   *
   * @access public
   * @param BaseTypeStructure ...$items
   * @return void
   */
  public function addItem(BaseTypeStructure ...$items): void
  {
    foreach ($items as $item) {
      $this->items[$item->name] = $item;
    }
    $this->buildName();
  }

  /**
   * 打包名称
   *
   * @return void
   */
  private function buildName(): void
  {
    $names = array_keys($this->items);
    $types = implode(' | ', $names);
    $this->name = "Array<$types>";
  }
}
