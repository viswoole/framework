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

namespace Viswoole\Core;

use ReflectionAttribute;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\Atomic;
use Viswoole\Core\Validate\Rules\RuleAbstract;
use Viswoole\Core\Validate\Type;

/**
 * 原子类型校验
 */
class Validate
{
  /**
   * 验证扩展类型
   *
   * @param ReflectionAttribute[]|RuleAbstract[] $rules 扩展验证规则
   * @param mixed $value 验证值
   * @return mixed
   * @throws ValidateException
   */
  public static function checkRules(
    array|ReflectionAttribute|RuleAbstract $rules,
    mixed                                  $value
  ): mixed
  {
    if (!is_array($rules)) $rules = [$rules];
    foreach ($rules as $attribute) {
      if ($attribute instanceof ReflectionAttribute) {
        $instance = $attribute->newInstance();
        // 判断是否为扩展规则
        if ($instance instanceof RuleAbstract) {
          $value = $instance->validate($value);
        }
      } elseif ($attribute instanceof RuleAbstract) {
        $value = $attribute->validate($value);
      }
    }
    return $value;
  }

  /**
   * 验证变量类型
   *
   * @param mixed $value 变量值
   * @param ReflectionType[]|string[]|Type[]|string|Type|ReflectionType $types 类型,多类型匹配用数组
   * @return mixed
   */
  public static function check(mixed $value, array|string|Type|ReflectionType $types): mixed
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
  private static function checkType(mixed $value, string|array $type): mixed
  {
    if (is_array($type)) {
      $value = self::checkTypes($value, $type);
    } else if (Atomic::isAtomicType($type)) {
      $value = Atomic::$type($value);
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
  private static function checkTypes(mixed $value, array $types): mixed
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
   * @param mixed $case 枚举item name
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
   * 判断是否为一个类的实例，如果$value传入的是类的构造参数或是类实例，则验证通过
   *
   * @param string $class 类或接口
   * @param mixed $value 值
   * @return object 返回类的实例
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
