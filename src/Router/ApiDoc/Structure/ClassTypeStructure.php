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
 * 类类型结构基类，扩展 TypeStructure 增加命名空间和描述信息
 */
abstract class ClassTypeStructure extends TypeStructure
{
  /**
   * @var string 命名空间
   */
  public string $namespace = '';
  /**
   * @var string 类描述
   */
  public string $description = '';
}
