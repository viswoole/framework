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

use JsonSerializable;
use Override;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * 用于声明字段结构，声明请求参数
 */
class FieldStructure implements JsonSerializable
{
  /**
   * @var BaseTypeStructure[] 参数类型列表
   */
  public array $types;

  /**
   * 参数结构描述
   *
   * @param string $name 名称
   * @param string $description 描述
   * @param bool $allowNull 是否允许为null
   * @param mixed $default 默认值
   * @param ReflectionType|BaseTypeStructure|array|null $type 参数类型,支持传入反射类型、类型结构、类型结构数组
   */
  public function __construct(
    public string                               $name,
    public string                               $description,
    public bool                                 $allowNull,
    public mixed                                $default,
    null|ReflectionType|BaseTypeStructure|array $type
  )
  {
    if ($type instanceof BaseTypeStructure) {
      $this->types = [$type];
    } elseif (is_array($type)) {
      $this->types = $type;
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
      return [new BaseTypeStructure('mixed')];
    }
    if ($type instanceof ReflectionIntersectionType) {
      $types = [new ObjectStructure([])];
    } elseif ($type instanceof ReflectionUnionType) {
      $types = array_map(function (ReflectionNamedType|ReflectionIntersectionType $typeItem) {
        if ($typeItem instanceof ReflectionIntersectionType) {
          return new ObjectStructure([]);
        } else {
          return $this->parseNamedType($typeItem);
        }
      }, $type->getTypes());
    } else {
      $types = [$this->parseNamedType($type)];
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
      return new  BaseTypeStructure($name);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function jsonSerialize(): array
  {
    return [
      'name' => $this->name,
      'description' => $this->description,
      'allowNull' => $this->allowNull,
      'default' => $this->default,
      'types' => $this->types
    ];
  }
}
