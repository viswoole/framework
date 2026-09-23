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

declare(strict_types=1);

namespace Viswoole\Database\Entity;

use ReflectionProperty;

/**
 * 实体属性元数据
 *
 * 描述实体类中一个"字段属性"与数据库列的映射关系及类型信息，
 * 由 {@see EntityHydrator} 通过反射构建并按实体类缓存，供水合
 * （行数据 → 属性）与脱水（属性 → 列数据）使用。
 *
 * @see EntityHydrator
 */
final class PropertyMeta
{
  /**
   * 类型种类：int|float|string|bool|array|datetime|enum|mixed
   *
   * - int/float/string/bool：执行严格强转
   * - array/mixed：原样透传（适用于 JSON 列）
   * - datetime：字符串/时间戳 → 目标日期时间对象
   * - enum：标量 → 回退枚举实例
   */
  public const string KIND_INT = 'int';
  public const string KIND_FLOAT = 'float';
  public const string KIND_STRING = 'string';
  public const string KIND_BOOL = 'bool';
  public const string KIND_ARRAY = 'array';
  public const string KIND_DATETIME = 'datetime';
  public const string KIND_ENUM = 'enum';
  public const string KIND_MIXED = 'mixed';

  /**
   * @param string $propertyName 属性名（驼峰）
   * @param string $column 数据库列名（蛇形）
   * @param ReflectionProperty $prop 属性反射实例，用于读写属性值
   * @param string $kind 类型种类，取值见类常量 KIND_*
   * @param bool $isNullable 属性是否允许 null（?int 等可空类型）
   * @param string|null $targetClass enum 的枚举类名，或 datetime 的具体日期类名
   */
  public function __construct(
    public readonly string             $propertyName,
    public readonly string             $column,
    public readonly ReflectionProperty $prop,
    public readonly string             $kind,
    public readonly bool               $isNullable,
    public readonly ?string            $targetClass = null
  )
  {
  }
}
