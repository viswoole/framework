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
class ArrayTypeStructure extends TypeStructure
{
  /**
   * 构建数组结构
   *
   * @param TypeStructure ...$items 数组元素结构
   */
  public function __construct(TypeStructure ...$items)
  {
    parent::__construct(Types::Array);
    $this->addItem(...$items);
  }

  /**
   * 追加数组元素结构
   *
   * @access public
   * @param TypeStructure ...$items
   * @return void
   */
  public function addItem(TypeStructure ...$items): void
  {
    $this->properties = array_merge($this->properties, $items);
    $this->buildName();
  }

  /**
   * 打包名称
   *
   * @return void
   */
  private function buildName(): void
  {
    $names = [];
    foreach ($this->properties as $item) $names[] = $item->getName();
    $types = implode(' | ', $names);
    $this->name = "Array<$types>";
  }
}
