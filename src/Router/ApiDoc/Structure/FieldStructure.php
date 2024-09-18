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
   * @var array<string, BaseTypeStructure> 参数类型列表
   */
  public array $types = [];

  /**
   * 参数结构描述
   *
   * @param string $name 名称
   * @param string $description 描述
   * @param bool $allowNull 是否允许为null
   * @param mixed $default 默认值
   * @param ReflectionType|BaseTypeStructure|array|Types|null $type 参数类型,支持传入反射类型、类型结构、类型结构数组或基本类型
   */
  public function __construct(
    public string                                     $name,
    public string                                     $description,
    public bool                                       $allowNull,
    public mixed                                      $default,
    null|ReflectionType|BaseTypeStructure|array|Types $type
  )
  {
    if ($type instanceof Types) {
      $type = new BaseTypeStructure($type);
      $this->types[$type->getName()] = $type;
    } elseif ($type instanceof BaseTypeStructure) {
      $this->types[$type->getName()] = $type;
    } elseif (is_array($type)) {
      foreach ($type as $typeItem) {
        if ($typeItem instanceof BaseTypeStructure) {
          $this->types[$typeItem->getName()] = $typeItem;
        } elseif ($typeItem instanceof Types) {
          $typeItem = new BaseTypeStructure($typeItem);
          $this->types[$typeItem->getName()] = $typeItem;
        }
      }
    } else {
      $this->types = $this->parseTypes($type);
    }
  }

  /**
   * 解析类型
   *
   * @param ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType|null $type
   * @return BaseTypeStructure[]
   */
  private function parseTypes(
    null|ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType $type
  ): array
  {
    if (is_null($type)) {
      return ['mixed' => new BaseTypeStructure()];
    }
    if ($type instanceof ReflectionIntersectionType) {
      $types = [new BaseTypeStructure(Types::Object)];
    } elseif ($type instanceof ReflectionUnionType) {
      $types = [];
      foreach ($type->getTypes() as $typeItem) {
        if ($typeItem instanceof ReflectionIntersectionType) {
          $type = new BaseTypeStructure(Types::Object);
        } else {
          $type = $this->parseNamedType($typeItem);
        }
        $types[$type->name] = $type;
      }
    } else {
      $type = $this->parseNamedType($type);
      $types[$type->name] = $type;
    }
    return $types;
  }

  /**
   * 解析命名类型
   *
   * @param ReflectionNamedType $type
   * @return BaseTypeStructure
   */
  private function parseNamedType(ReflectionNamedType $type): BaseTypeStructure
  {
    $isBuiltin = $type->isBuiltin();
    $name = $type->getName();
    // 如果不是内置类型 则判断其是否为类或枚举
    if (!$isBuiltin) {
      if (enum_exists($name)) {
        return new EnumStructure($name);
      } else {
        return new ObjectStructure($name);
      }
    } else {
      return match ($name) {
        'bool' => new BaseTypeStructure(Types::Bool),
        'float' => new BaseTypeStructure(Types::Float),
        'int' => new BaseTypeStructure(Types::Int),
        'null' => new BaseTypeStructure(Types::Null),
        'string' => new BaseTypeStructure(Types::String),
        default => new BaseTypeStructure(Types::Mixed),
      };
    }
  }
}
