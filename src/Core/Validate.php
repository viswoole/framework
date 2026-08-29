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
 * 类型校验器
 *
 * 提供参数类型校验能力，支持内置类型、联合类型、交集类型、枚举、类实例和自定义验证规则。
 * 在容器依赖注入时自动调用，确保参数类型安全。
 */
class Validate
{
  /**
   * 执行扩展验证规则链，依次调用每个规则的 validate 方法
   *
   * 规则抛出 ValidateException 时自动替换消息中的 {:name} 占位符为参数名
   * （见 withContext），使错误信息能定位到具体参数。
   * 注意：规则实例为容器预建的共享实例（协程间复用），禁止在实例上存储请求级状态；
   * 自定义规则需要参数名时，可声明 validate(mixed $value, string $name = '') 接收
   *
   * @param ReflectionAttribute[]|BaseValidateRule[] $rules 扩展验证规则列表
   * @param mixed $value 待验证的值
   * @param mixed ...$args 额外参数（如参数名称），传递给规则的 validate 方法
   * @return mixed 验证通过的值（可能被规则转换）
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
        try {
          $value = call_user_func_array([$instance, 'validate'], [$value, ...$args]);
        } catch (ValidateException $e) {
          throw self::withContext($e, ...$args);
        }
      }
    }
    return $value;
  }

  /**
   * 为校验异常替换 {:name} 占位符为参数名，使错误信息能定位到具体参数
   *
   * 消息未使用占位符时原样返回，开发者的自定义文案不受影响；
   * 占位符可出现多次，多条错误信息逐条替换；
   * 参数名缺失时占位符替换为空串并去除行首残留空白
   *
   * @param ValidateException $e 原始校验异常
   * @param mixed ...$args 校验上下文参数，第一个参数为参数名称，缺失时原样返回
   * @return ValidateException 占位符替换后的异常，原异常挂载为 previous
   */
  public static function withContext(ValidateException $e, mixed ...$args): ValidateException
  {
    if (!str_contains($e->getMessage(), '{:name}')) return $e;
    $name = $args[0] ?? null;
    $replacement = is_string($name) && $name !== '' ? '$' . $name : '';
    $replaced = str_replace('{:name}', $replacement, $e->getError());
    if (is_array($replaced)) {
      $replaced = array_map(
        fn($item) => is_string($item) ? preg_replace('/^\s+/', '', $item) : $item,
        $replaced
      );
    } else {
      $replaced = preg_replace('/^\s+/', '', $replaced);
    }
    return new ValidateException($replaced, $e->getCode(), $e);
  }

  /**
   * 校验值是否符合指定类型，支持联合类型（数组）和单类型
   *
   * @param mixed $value 待校验的值
   * @param ReflectionType[]|string[]|Type[]|string|Type|ReflectionType $types 期望的类型
   * @return mixed 校验通过的值（可能被转换）
   * @throws ValidateException 类型不匹配时抛出
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
   * 将各种类型表示统一格式化为字符串或字符串数组
   *
   * 处理 Type 枚举、ReflectionUnionType/IntersectionType/NamedType 和管道符分隔的联合类型字符串
   * 公开供容器在构建参数元数据缓存时预格式化，避免每请求重复转换
   *
   * @param Type|ReflectionType|string $type 原始类型表示
   * @return string|array 格式化后的类型字符串或联合类型数组
   */
  public static function formatType(Type|ReflectionType|string $type): string|array
  {
    if ($type instanceof Type) return $type->value;
    if ($type instanceof ReflectionUnionType) {
      $tArr = [];
      foreach ($type->getTypes() as $childType) {
        if ($childType instanceof ReflectionNamedType) {
          $tArr[] = $childType->getName();
        } else {
          // 修复#10: (string)$childType->getTypes()对数组转字符串得到"Array"，改为(string)$childType
          $tArr[] = (string)$childType;
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
   * 对单个类型进行校验，按内置类型→交集类型→枚举→类/接口的优先级分发
   *
   * @param mixed $value 待校验的值
   * @param string|array $type 格式化后的类型字符串或联合类型数组
   * @return mixed 校验通过的值
   * @throws ValidateException 类型不匹配时抛出
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
   * 联合类型校验，依次尝试每个类型，全部不匹配时抛出最后一个异常
   *
   * @param mixed $value 待校验的值
   * @param string[] $types 联合类型列表
   * @return mixed 校验通过的值
   * @throws ValidateException 所有类型均不匹配时抛出
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
   * 交集类型校验，值必须同时满足所有类型约束（即 instanceof 所有类）
   *
   * @param string $type 交集类型字符串，如 'A&B'
   * @param mixed $value 待校验的值
   * @return mixed 校验通过的值
   * @throws ValidateException 不满足任一类型约束时抛出
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
   * 枚举类型校验，支持枚举实例、名称字符串和数字索引三种输入形式
   *
   * @param string $enum 枚举类名
   * @param mixed $case 枚举实例、名称字符串或数字索引
   * @return UnitEnum 匹配到的枚举项
   * @throws ValidateException 无法匹配到枚举项时抛出
   */
  public static function enum(string $enum, mixed $case): UnitEnum
  {
    if ($case instanceof $enum) return $case;
    $cases = call_user_func($enum . '::cases');
    // 兼容用数字索引枚举
    if (is_int($case)) {
      // 修复#11: 枚举数字索引未做边界检查，改为isset检查；修复return throw语法错误
      if (isset($cases[$case])) {
        return $cases[$case];
      } else {
        throw new ValidateException('must be between 0 and ' . (count($cases) - 1));
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
   * 类/接口类型校验，若值已是实例则直接返回，否则尝试通过容器创建实例
   *
   * @param string $class 类名或接口名
   * @param mixed $value 待校验的值，若为数组则作为构造参数传入容器
   * @return object 类实例
   * @throws ValidateException 验证失败时抛出
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
