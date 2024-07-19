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

use Closure;
use ReflectionNamedType;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Exception\ValidateException;

/**
 * 原子类型校验
 */
class Atomic
{
  // PHP内置原子类型
  public const array TYPES = [
    'bool' => ['ts' => 'boolean', 'dart' => 'bool'],
    'null' => ['ts' => 'null', 'dart' => 'Null'],
    'int' => ['ts' => 'number', 'dart' => 'int'],
    'float' => ['ts' => 'number', 'dart' => 'double'],
    'string' => ['ts' => 'string', 'dart' => 'String'],
    'array' => ['ts' => 'Array<any>', 'dart' => 'List<dynamic>'],
    'object' => ['ts' => '{[key: string]: any}', 'dart' => 'Map<String, dynamic>'],
    'true' => ['ts' => 'true', 'dart' => 'bool'],
    'false' => ['ts' => 'false', 'dart' => 'bool'],
    'boolean' => ['ts' => 'boolean', 'dart' => 'bool'],
    'integer' => ['ts' => 'number', 'dart' => 'int'],
    'double' => ['ts' => 'number', 'dart' => 'double'],
    'iterable' => ['ts' => 'Iterable<any>', 'dart' => 'Iterable<dynamic>'],
    'mixed' => ['ts' => 'any', 'dart' => 'dynamic'],
    'callable' => ['ts' => 'Function', 'dart' => 'Function'],
    'Closure' => ['ts' => 'Function', 'dart' => 'Function']
  ];

  /**
   * 验证是否为bool
   *
   * @param mixed $value
   * @return bool
   * @throws ValidateException 验证失败
   */
  public static function boolean(mixed $value): bool
  {
    return self::bool($value);
  }

  /**
   * 判断是否为bool，带自动转换
   *
   * @param mixed $value
   * @return bool
   * @throws ValidateException 验证失败
   */
  public static function bool(mixed $value): bool
  {
    $value = match ($value) {
      1, 'true', '1', 'yes', 'on' => true,
      0, '0', 'false', 'no', 'off' => false,
      default => $value,
    };
    if (!is_bool($value)) self::unifiedExceptionHandling('bool', $value);
    return $value;
  }

  /**
   * 统一异常处理
   *
   * @param string $type
   * @param mixed $value
   * @return void
   * @throws ValidateException
   */
  public static function unifiedExceptionHandling(string $type, mixed $value): void
  {
    $vType = gettype($value);
    throw new ValidateException("must be of type $type , $vType given");
  }

  /**
   * 判断Closure
   *
   * @param mixed $value
   * @return callable
   */
  public static function Closure(mixed $value): Closure
  {
    if (!$value instanceof Closure) self::unifiedExceptionHandling('Closure', $value);
    return $value;
  }

  /**
   * 判断callable
   *
   * @param mixed $value
   * @return callable
   */
  public static function callable(mixed $value): callable
  {
    if (!is_callable($value)) self::unifiedExceptionHandling('callable', $value);
    return $value;
  }

  /**
   * 验证是否为int
   *
   * @param mixed $value
   * @return int
   * @throws ValidateException 验证失败
   */
  public static function integer(mixed $value): int
  {
    return self::int($value);
  }

  /**
   * 验证是否为int
   *
   * @param mixed $value
   * @return int
   * @throws ValidateException 验证失败
   */
  public static function int(mixed $value): int
  {
    if (is_numeric($value)) $value = intval($value);
    if (!is_int($value)) self::unifiedExceptionHandling('int', $value);
    return $value;
  }

  /**
   * 任意类型
   *
   * @param mixed $value
   * @return mixed
   */
  public static function mixed(mixed $value): mixed
  {
    return $value;
  }

  /**
   * 验证是否为double
   *
   * @param mixed $value
   * @return float
   * @throws ValidateException 验证失败
   */
  public static function double(mixed $value): float
  {
    return self::float($value);
  }

  /**
   * 验证是否为float
   *
   * @param mixed $value
   * @return float
   * @throws ValidateException 验证失败
   */
  public static function float(mixed $value): float
  {
    if (is_numeric($value)) $value = floatval($value);
    if (!is_float($value)) self::unifiedExceptionHandling('float', $value);
    return $value;
  }

  /**
   * 判断是否为null
   *
   * @param mixed $value
   * @return null
   * @throws ValidateException 验证失败
   */
  public static function null(mixed $value): null
  {
    if (empty($value)) return null;
    self::unifiedExceptionHandling('null', $value);
  }

  /**
   * 验证是否为可迭代对象
   *
   * @param mixed $value
   * @return bool
   * @throws ValidateException 验证失败
   */
  public static function iterable(mixed $value): mixed
  {
    if (!is_iterable($value)) self::unifiedExceptionHandling('iterable', $value);
    return $value;
  }

  /**
   * 验证是否为true
   *
   * @param mixed $value
   * @return true
   * @throws ValidateException 验证失败
   */
  public static function true(mixed $value): true
  {
    if ($value) return true;
    self::unifiedExceptionHandling('true', $value);
  }

  /**
   * 验证是否为false
   *
   * @param mixed $value
   * @return false
   * @throws ValidateException 验证失败
   */
  public static function false(mixed $value): false
  {
    if (!$value) return false;
    self::unifiedExceptionHandling('false', $value);
  }

  /**
   * 检测是否为字符串
   *
   * @param mixed $value
   * @return string
   * @throws ValidateException 验证失败
   */
  public static function string(mixed $value): string
  {
    if (!is_string($value)) self::unifiedExceptionHandling('string', $value);
    return $value;
  }

  /**
   * 检测是否为数组
   *
   * @param mixed $value
   * @return array
   * @throws ValidateException 验证失败
   */
  public static function array(mixed $value): array
  {
    if (!is_array($value)) self::unifiedExceptionHandling('array', $value);
    return $value;
  }

  /**
   * 检测是否为对象,如果为关联数组自动转换为对象类型
   *
   * @param mixed $value
   * @return object
   * @throws ValidateException 验证失败
   */
  public static function object(mixed $value): object
  {
    if (Arr::isAssociativeArray($value)) {
      $value = (object)$value;
    } elseif (!is_object($value)) {
      self::unifiedExceptionHandling('object', $value);
    }
    return $value;
  }

  /**
   * 判断是否内置原子类型
   *
   * @param string|Type|ReflectionNamedType $type
   * @return bool
   */
  public static function isAtomicType(string|Type|ReflectionNamedType $type): bool
  {
    if ($type instanceof Type) {
      $type = $type->value;
    } elseif ($type instanceof ReflectionNamedType) {
      $type = $type->getName();
    }
    return array_key_exists($type, self::TYPES);
  }
}
