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
 * PHP 内置类型枚举
 *
 * 定义所有 PHP 内置原子类型的枚举值，用于 Validate 校验时的类型标识
 */
enum Type: string
{
  /** 布尔类型（含 true 和 false） */
  case BOOL = 'bool';
  /** 空值类型 */
  case NULL = 'null';
  /** 整数类型 */
  case INT = 'int';
  /** 浮点数类型 */
  case FLOAT = 'float';
  /** 字符串类型 */
  case STRING = 'string';
  /** 数组类型 */
  case ARRAY = 'array';
  /** 对象类型 */
  case OBJECT = 'object';
  /** 严格 true 类型（PHP 8.2+） */
  case TRUE = 'true';
  /** 严格 false 类型（PHP 8.2+） */
  case FALSE = 'false';
  /** 布尔类型别名，等同于 bool */
  case BOOLEAN = 'boolean';
  /** 整数类型别名，等同于 int */
  case INTEGER = 'integer';
  /** 浮点数类型别名，等同于 float */
  case DOUBLE = 'double';
  /** 可迭代类型（数组或 Traversable） */
  case ITERABLE = 'iterable';
  /** 混合类型，接受任意值 */
  case MIXED = 'mixed';
  /** 可调用类型（函数名、闭包、实现 __invoke 的对象等） */
  case CALLABLE = 'callable';
  /** 闭包类型 */
  case CLOSURE = 'Closure';
}
