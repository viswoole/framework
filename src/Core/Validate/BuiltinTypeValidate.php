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
 * PHP 内置类型校验器
 *
 * 为每种 PHP 内置类型提供校验与自动转换方法，
 * 支持布尔值宽松转换、数字字符串转数值、关联数组转对象等。
 * 校验失败时统一抛出 ValidateException。
 */
class BuiltinTypeValidate
{
  /**
   * @var string[] PHP 内置原子类型名称列表，用于 isBuiltin() 判断
   */
  public const array TYPES = [
    'bool',
    'null',
    'int',
    'float',
    'string',
    'array',
    'object',
    'true',
    'false',
    'boolean',
    'integer',
    'double',
    'iterable',
    'mixed',
    'callable',
    'Closure'
  ];

  /**
   * boolean 别名，代理到 bool()
   *
   * @param mixed $value 待校验的值
   * @return bool 校验通过的布尔值
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function boolean(mixed $value): bool
  {
    return self::bool($value);
  }

  /**
   * 校验布尔类型，支持宽松转换（'true'/'on'/'yes'/1 → true，'false'/'off'/'no'/0 → false）
   *
   * @param mixed $value 待校验的值
   * @return bool 校验通过的布尔值
   * @throws ValidateException 类型不匹配时抛出
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
   * 统一的校验失败异常抛出，格式化实际类型与期望类型
   *
   * @param string $type 期望的类型名
   * @param mixed $value 实际的值
   * @throws ValidateException 始终抛出
   */
  public static function unifiedExceptionHandling(string $type, mixed $value): void
  {
    $vType = gettype($value);
    throw new ValidateException("must be of type $type , $vType given");
  }

  /**
   * 校验闭包类型
   *
   * @param mixed $value 待校验的值
   * @return Closure 校验通过的闭包
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function Closure(mixed $value): Closure
  {
    if (!$value instanceof Closure) self::unifiedExceptionHandling('Closure', $value);
    return $value;
  }

  /**
   * 校验可调用类型
   *
   * @param mixed $value 待校验的值
   * @return callable 校验通过的可调用结构
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function callable(mixed $value): callable
  {
    if (!is_callable($value)) self::unifiedExceptionHandling('callable', $value);
    return $value;
  }

  /**
   * integer 别名，代理到 int()
   *
   * @param mixed $value 待校验的值
   * @return int 校验通过的整数值
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function integer(mixed $value): int
  {
    return self::int($value);
  }

  /**
   * 校验整数类型，数字字符串自动转为整数（含科学计数法）
   *
   * 超出 int64 可表示范围的数字字符串（如恶意/异常雪花 ID 入参）直接拒绝，
   * 不做 (int) 钳制——强转发生在自定义验证规则之前，静默钳制会导致业务侧
   * 「超范围拒绝」类规则被整体绕过
   *
   * @param mixed $value 待校验的值
   * @return int 校验通过的整数值
   * @throws ValidateException 类型不匹配或数值超出 int 可表示范围时抛出
   */
  public static function int(mixed $value): int
  {
    if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
      // 修复: 纯整数字符串必须 (int) 直转——经 float 中转时超出 2^53 的
      // 大整数（如雪花 ID）会发生精度丢失（末几位被舍入指向错误记录）；
      // 强转前校验 int64 范围——(int) 对超范围字符串会静默钳制为
      // PHP_INT_MAX / PHP_INT_MIN
      if (!self::isWithinIntRange($value)) {
        throw new ValidateException("数字 {$value} 超出 int 可表示范围");
      }
      $value = (int)$value;
    } elseif (is_numeric($value) && !is_int($value)) {
      // 非整型数字串（如科学计数法 '1e5'）才经 float 中转，
      // 确保 intval() 截断科学计数法的问题不复现；
      // float 中转后校验可表示性——超出 int 范围的浮点强转同样会静默钳制
      $float = (float)$value;
      if ($float >= (float)PHP_INT_MAX || $float <= (float)PHP_INT_MIN) {
        throw new ValidateException("数字 {$value} 超出 int 可表示范围");
      }
      $value = (int)$float;
    }
    if (!is_int($value)) self::unifiedExceptionHandling('int', $value);
    return $value;
  }

  /**
   * 判断整数字符串是否在 int64 可表示范围内（含边界，支持前导零与负号）
   *
   * 同长度下逐字符比较等价于数值比较（无前导零时），
   * 避免 bcmath 依赖；负界比正界多一位（PHP_INT_MIN = -9223372036854775808）
   *
   * @param string $num 已通过 /^-?\d+$/ 校验的整数字符串
   * @return bool 在 int64 范围内返回 true
   */
  private static function isWithinIntRange(string $num): bool
  {
    $negative = $num[0] === '-';
    $digits = ltrim($negative ? substr($num, 1) : $num, '0');
    if ($digits === '') return true; // '0' / '-0' / 全零
    $limit = $negative ? '9223372036854775808' : '9223372036854775807';
    if (strlen($digits) !== strlen($limit)) return strlen($digits) < strlen($limit);
    return strcmp($digits, $limit) <= 0;
  }

  /**
   * mixed 类型不做校验，直接返回原值
   *
   * @param mixed $value 任意值
   * @return mixed 原值
   */
  public static function mixed(mixed $value): mixed
  {
    return $value;
  }

  /**
   * double 别名，代理到 float()
   *
   * @param mixed $value 待校验的值
   * @return float 校验通过的浮点数值
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function double(mixed $value): float
  {
    return self::float($value);
  }

  /**
   * 校验浮点数类型，数字字符串自动转为浮点数
   *
   * @param mixed $value 待校验的值
   * @return float 校验通过的浮点数值
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function float(mixed $value): float
  {
    if (is_numeric($value)) $value = floatval($value);
    if (!is_float($value)) self::unifiedExceptionHandling('float', $value);
    return $value;
  }

  /**
   * 校验 null 类型，使用严格比较（=== null）
   *
   * @param mixed $value 待校验的值
   * @return null 校验通过返回 null
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function null(mixed $value): null
  {
    // 修复: empty() 会将 0, '', '0', false, [] 误判为 null，应使用严格比较
    if ($value === null) return null;
    self::unifiedExceptionHandling('null', $value);
  }

  /**
   * 校验可迭代类型（数组或 Traversable 对象）
   *
   * @param mixed $value 待校验的值
   * @return mixed 校验通过的可迭代值
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function iterable(mixed $value): mixed
  {
    if (!is_iterable($value)) self::unifiedExceptionHandling('iterable', $value);
    return $value;
  }

  /**
   * 校验严格 true 类型，使用 === true 比较
   *
   * @param mixed $value 待校验的值
   * @return true 校验通过返回 true
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function true(mixed $value): true
  {
    // 修复: 使用严格比较 === true，避免 1、"1" 等真值通过校验
    if ($value === true) return true;
    self::unifiedExceptionHandling('true', $value);
  }

  /**
   * 校验严格 false 类型，使用 === false 比较
   *
   * @param mixed $value 待校验的值
   * @return false 校验通过返回 false
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function false(mixed $value): false
  {
    // 修复: 使用严格比较 === false，避免 0、"" 等假值通过校验
    if ($value === false) return false;
    self::unifiedExceptionHandling('false', $value);
  }

  /**
   * 校验字符串类型
   *
   * @param mixed $value 待校验的值
   * @return string 校验通过的字符串
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function string(mixed $value): string
  {
    if (!is_string($value)) self::unifiedExceptionHandling('string', $value);
    return $value;
  }

  /**
   * 校验数组类型
   *
   * @param mixed $value 待校验的值
   * @return array 校验通过的数组
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function array(mixed $value): array
  {
    if (!is_array($value)) self::unifiedExceptionHandling('array', $value);
    return $value;
  }

  /**
   * 校验对象类型，关联数组自动转为 stdClass 对象
   *
   * @param mixed $value 待校验的值
   * @return object 校验通过的对象
   * @throws ValidateException 类型不匹配时抛出
   */
  public static function object(mixed $value): object
  {
    if (is_array($value) && Arr::isAssociativeArray($value)) {
      $value = (object)$value;
    } elseif (!is_object($value)) {
      self::unifiedExceptionHandling('object', $value);
    }
    return $value;
  }

  /**
   * 判断类型名是否为 PHP 内置原子类型
   *
   * @param string|Type|ReflectionNamedType $type 类型名、Type 枚举或反射类型
   * @return bool 是内置类型返回 true
   */
  public static function isBuiltin(string|Type|ReflectionNamedType $type): bool
  {
    if ($type instanceof Type) {
      $type = $type->value;
    } elseif ($type instanceof ReflectionNamedType) {
      $type = $type->getName();
    }
    return in_array($type, self::TYPES);
  }
}
