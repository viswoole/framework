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

use Override;

/**
 * 类型结构
 */
class TypeStructure extends BaseStructure
{
  /**
   * @param string $name 类型名称
   * @param string $type 实际类型
   * @param bool $isBuiltin 是否为内置类型
   * @param EnumStructure|ObjectStructure|null $structure 如果是对象或者枚举，则包含对象结构或枚举结构
   */
  public function __construct(
    public string                             $name,
    public string                             $type,
    public bool                               $isBuiltin,
    public null|EnumStructure|ObjectStructure $structure = null
  )
  {

  }

  /**
   * @inheritDoc
   */
  #[Override] public function jsonSerialize(): array
  {
    return [
      'name' => $this->name,
      'type' => $this->type,
      'isBuiltin' => $this->isBuiltin,
      'structure' => $this->structure
    ];
  }
}
