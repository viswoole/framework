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
use Viswoole\Core\Validate\BaseValidateRule;
use Viswoole\Core\Validate\BuiltinTypeValidate;
use Viswoole\Core\Validate\Type;

/**
 * 原子类型校验
 */
class Validate
{
  /**
   * 验证扩展类型
   *
   * @param ReflectionAttribute[]|BaseValidateRule[] $rules 扩展验证规则
   * @param mixed $value 验证值
   * @param mixed ...$args 额外需要传递给验证器的参数（依赖注入时会传入属性或参数名称，供validate使用）
   * @return mixed
   */
  public static function checkRules(
    array|ReflectionAttribute|BaseValidateRule $rules,
    mixed                                      $value,
    mixed                                      ...$args
  ): mixed
  {
    if (empty($rules)) return $value;
    if (!is_array($rules)) $rules = [$rules];
    foreach ($rules as $attribute) {
      $instance = $attribute;
      if ($attribute instanceof ReflectionAttribute) {
        $instance = $attribute->newInstance();
      }
      // 判断是否为扩展规则
      if ($instance instanceof BaseValidateRule) {
        $value = call_user_func_array([$instance, 'validate'], [$value, ...$args]);
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
    if ($type instanceof ReflectionNamedType) {
      $typeString = $type->getName();
      return $type->allowsNull() ? ['null', $typeString] : $typeString;
    }
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
    } elseif (BuiltinTypeValidate::isBuiltin($type)) {
      $value = BuiltinTypeValidate::$type($value);
    } elseif (str_contains($type, '&')) {
      $value = self::intersection($type, $value);
    } elseif (enum_exists($type)) {
      $value = self::enum($type, $value);
    } elseif (class_exists($type) || interface_exists($type)) {
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
    foreach ($types as $index => $type) {
      try {
        $value = self::checkType($value, $type);
        $valid = true;
        break;
      } catch (ValidateException $e) {
        if ($index === count($types) - 1) {
          throw $e;
        }
      }
    }
    if (!$valid) {
      throw new ValidateException(
        'must match the type ' . implode('|', $types)
      );
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
    if ($case instanceof $enum) return $case;
    $cases = call_user_func($enum . '::cases');
    // 兼容用数字索引枚举
    if (is_int($case)) {
      if ($cases[$case]) {
        return $cases[$case];
      } else {
        return throw new ValidateException('must be between 0 and ' . count($cases) - 1);
      }
    }
    $names = [];
    foreach ($cases as $item) {
      $names[strtolower($item->name)] = $item;
    }
    if (is_string($case)) {
      $case = strtolower(trim($case));
      if (isset($names[$case])) {
        return $names[$case];
      }
    }
    $names = implode('|', array_keys($names));
    throw new ValidateException("must be between $names");
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
    if ($value instanceof $class) return $value;
    $app = App::factory();
    if (interface_exists($class)) {
      if ($app->has($class)) return $app->make($class, is_array($value) ? $value : []);
    } else {
      // 如果验证通过，则将值注入,得到新实例
      return $app->make($class, is_array($value) ? $value : []);
    }
    throw new ValidateException(
      "must be an instance of $class" . ' , ' . gettype($value) . ' given'
    );
  }
}
