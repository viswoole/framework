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

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * 用于声明字段结构，声明请求参数
 */
class FieldStructure
{
  /**
   * @var array<string, TypeStructure> 参数类型列表
   */
  public array $types = [];

  /**
   * 参数结构描述
   *
   * @param string $name 名称
   * @param string $description 描述
   * @param bool $allowNull 是否允许为null
   * @param mixed $default 默认值
   * @param ReflectionType|TypeStructure|array|Types|null $type 参数类型,支持传入反射类型、类型结构、类型结构数组或基本类型
   * @param array $dependMap [类完全名称=>类名] 引用对象依赖映射
   */
  public function __construct(
    public string                                 $name,
    public string                                 $description = '',
    public bool                                   $allowNull = false,
    public mixed                                  $default = null,
    null|ReflectionType|TypeStructure|array|Types $type = Types::Mixed,
    array                                         &$dependMap = [],
  )
  {
    if ($type instanceof Types) {
      $type = new TypeStructure($type);
      $this->types[$type->getName()] = $type;
    } elseif ($type instanceof TypeStructure) {
      $this->types[$type->getName()] = $type;
    } elseif (is_array($type)) {
      foreach ($type as $typeItem) {
        if ($typeItem instanceof TypeStructure) {
          $this->types[$typeItem->getName()] = $typeItem;
        } elseif ($typeItem instanceof Types) {
          $typeItem = new TypeStructure($typeItem);
          $this->types[$typeItem->getName()] = $typeItem;
        }
      }
    } else {
      $this->types = $this->parseTypes($type, $dependMap);
    }
  }

  /**
   * 解析类型
   *
   * @param ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType|null $type
   * @param array<string,string> $dependMap 对象依赖映射[完整命名=>类型名称]
   * @return TypeStructure[]
   */
  private function parseTypes(
    null|ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType $type,
    array                                                                   &$dependMap,
  ): array
  {
    if (is_null($type)) {
      return ['mixed' => new TypeStructure()];
    }
    if ($type instanceof ReflectionIntersectionType) {
      $types = [new TypeStructure(Types::Object)];
    } elseif ($type instanceof ReflectionUnionType) {
      $types = [];
      foreach ($type->getTypes() as $typeItem) {
        if ($typeItem instanceof ReflectionIntersectionType) {
          $type = new TypeStructure(Types::Object);
        } else {
          $type = $this->parseNamedType($typeItem, $dependMap);
        }
        $types[$type->name] = $type;
      }
    } else {
      $type = $this->parseNamedType($type, $dependMap);
      $types[$type->name] = $type;
    }
    return $types;
  }

  /**
   * 解析命名类型
   *
   * @param ReflectionNamedType $type
   * @param array $dependMap
   * @return TypeStructure
   */
  private function parseNamedType(
    ReflectionNamedType $type,
    array               &$dependMap,
  ): TypeStructure
  {
    $isBuiltin = $type->isBuiltin();
    $name = $type->getName();
    // 如果不是内置类型 则判断其是否为类或枚举
    if (!$isBuiltin) {
      if (enum_exists($name)) {
        return new EnumStructure($name);
      } else {
        // 引用对象
        if (array_key_exists($name, $dependMap)) {
          return new TypeStructure(Types::Recursion, $dependMap[$name]);
        } else {
          return new ObjectStructure($name, dependMap: $dependMap);
        }
      }
    } else {
      return match ($name) {
        'bool', 'false', 'true' => new TypeStructure(Types::Bool),
        'float' => new TypeStructure(Types::Float),
        'int' => new TypeStructure(Types::Int),
        'null' => new TypeStructure(Types::Null),
        'string' => new TypeStructure(Types::String),
        default => new TypeStructure(Types::Mixed),
      };
    }
  }
}
