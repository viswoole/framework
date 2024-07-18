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

namespace Viswoole\Core\Validate;
/**
 * PHP内置类型枚举
 */
enum Type: string
{
  case BOOL = 'bool';
  case NULL = 'null';
  case INT = 'int';
  case FLOAT = 'float';
  case STRING = 'string';
  case ARRAY = 'array';
  case OBJECT = 'object';
  case TRUE = 'true';
  case FALSE = 'false';
  case BOOLEAN = 'boolean';
  case INTEGER = 'integer';
  case DOUBLE = 'double';
  case ITERABLE = 'iterable';
  case MIXED = 'mixed';
  case CALLABLE = 'callable';
  case CLOSURE = 'Closure';
}
