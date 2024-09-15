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

use InvalidArgumentException;
use ReflectionEnum;
use Viswoole\Router\ApiDoc\DocCommentTool;

/**
 * 枚举结构对象
 */
class EnumStructure extends ClassTypeStructure
{
  /**
   * @var array 枚举case列表
   */
  public readonly array $cases;

  /**
   * 构建枚举结构
   *
   * @param string $enum
   */
  public function __construct(string $enum)
  {
    parent::__construct('enum');
    if (!enum_exists($enum)) {
      throw new InvalidArgumentException('not a enum class');
    }
    $reflector = new ReflectionEnum($enum);
    $this->namespace = $reflector->getNamespaceName();
    $this->name = $reflector->getShortName();
    $docComment = $reflector->getDocComment() ?: '';
    $this->description = DocCommentTool::extractDocTitle($docComment);
    $this->cases = array_map(function ($case) {
      return $case->name;
    }, $reflector->getCases());
  }
}
