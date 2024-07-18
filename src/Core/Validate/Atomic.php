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
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;
use ViSwoole\Core\App;
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
   * 判断是否为bool
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
    if (!is_int($value)) self::unifiedExceptionHandling('int', $value);;
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
    if (!is_float($value)) self::unifiedExceptionHandling('float', $value);;
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
   * 检测是否为对象
   *
   * @param mixed $value
   * @return object
   * @throws ValidateException 验证失败
   */
  public static function object(mixed $value): object
  {
    if (!is_object($value)) self::unifiedExceptionHandling('object', $value);
    return $value;
  }

  /**
   * 验证变量类型
   *
   * @param mixed $value 变量值
   * @param ReflectionType[]|string[]|Type[]|string|Type|ReflectionType $types 类型,多类型匹配用数组
   * @return mixed
   */
  public static function validate(mixed $value, array|string|Type|ReflectionType $types): mixed
  {
    if (is_array($types)) {
      $types = array_map(fn($type) => self::formatType($type), $types);
      return self::checkType($value, $types);
    } else {
      return self::checkType($value, self::formatType($types));
    }
  }

  /**
   * 格式化类型
   *
   * @param Type|ReflectionType|string $type
   * @return string|array
   */
  private static function formatType(Type|ReflectionType|string $type): string|array
  {
    if ($type instanceof Type) return $type->value;
    if ($type instanceof ReflectionUnionType) {
      $tArr = [];
      foreach ($type->getTypes() as $childType) {
        if ($childType instanceof ReflectionNamedType) {
          $tArr[] = $childType->getName();
        } else {
          $tArr[] = (string)$childType->getTypes();
        }
      }
      return $tArr;
    }
    if ($type instanceof ReflectionIntersectionType) return (string)$type;
    if ($type instanceof ReflectionNamedType) return $type->getName();
    if (str_contains($type, '|')) return explode('|', $type);
    return $type;
  }

  /**
   * 验证值类型
   *
   * @param mixed $value
   * @param string|array $type
   * @return mixed
   */
  protected static function checkType(mixed $value, string|array $type): mixed
  {
    if (is_array($type)) {
      $value = self::checkTypes($value, $type);
    } else if (self::isAtomicType($type)) {
      $value = self::$type($value);
    } elseif (str_contains($type, '&')) {
      $value = self::intersection($type, $value);
    } elseif (enum_exists($type)) {
      $value = self::enum($type, $value);
    } elseif (class_exists($type)) {
      $value = self::class($type, $value);
    }
    return $value;
  }

  /**
   * 批量验证值是否为指定类型
   *
   * @param string $value 值
   * @param string[] $types 需要验证的类型
   * @return mixed
   */
  protected static function checkTypes(mixed $value, array $types): mixed
  {
    $valid = false;
    foreach ($types as $type) {
      try {
        $value = self::checkType($value, $type);
        $valid = true;
        break;
      } catch (ValidateException) {
        // 不做处理
      }
    }
    if (!$valid) {
      $types = implode('|', $types);
      throw new ValidateException("must match the $types" . ' , ' . gettype($value) . ' given');
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

  /**
   * 交集类型验证
   *
   * @param string $type
   * @param mixed $value
   * @return mixed
   * @throws ValidateException 验证失败
   */
  public static function intersection(string $type, mixed $value): mixed
  {
    if (str_starts_with($type, '(')) {
      $type = substr($type, 1, -1);
    }
    $types = explode('&', $type);
    foreach ($types as $type) {
      if (!$value instanceof $type) {
        return throw new ValidateException(
          "must match the intersection $type" . ' , ' . gettype($value) . ' given'
        );
      }
    }
    return $value;
  }

  /**
   * 验证枚举
   *
   * @param string $enum 枚举类
   * @param mixed $case $case
   * @return UnitEnum
   * @throws ValidateException 验证失败
   */
  public static function enum(string $enum, mixed $case): UnitEnum
  {
    $cases = call_user_func($enum . '::cases');
    $names = [];
    foreach ($cases as $item) {
      $names[$item->name] = $item;
    }
    if (isset($names[$case])) {
      return $names[$case];
    } else {
      $names = implode('|', array_keys($names));
      throw new ValidateException("must be between $names");
    }
  }

  /**
   * 判断是否为一个类的实例，如果$value传入的是类的构造参数则会验证合法性
   *
   * @param string $class 类或接口
   * @param mixed $value 值
   * @return object
   */
  public static function class(string $class, mixed $value): object
  {
    try {
      if ($value instanceof $class) return $value;
      if (!interface_exists($class)) {
        // 如果验证通过，则将值注入,得到新实例
        return App::factory()->make($class, is_array($value) ? $value : [$value]);
      }
    } catch (ValidateException $e) {
      throw new ValidateException(
        "must be an instance of $class" . ' , ' . gettype($value) . ' given', previous: $e
      );
    }
    throw new ValidateException(
      "must be an instance of $class" . ' , ' . gettype($value) . ' given'
    );
  }
}
