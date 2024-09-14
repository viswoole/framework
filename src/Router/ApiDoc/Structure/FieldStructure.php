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

use InvalidArgumentException;
use Override;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * 参数或属性字段结构描述
 */
class FieldStructure extends BaseStructure
{
  /**
   * @var TypeStructure[] 参数类型列表
   */
  public array $types;

  /**
   * 构建结构
   *
   * @param string $name 参数名称
   * @param string $description 参数描述
   * @param bool $allowNull 是否允许为null
   * @param mixed $default 默认值
   * @param ReflectionType|TypeStructure|array|null $type 参数类型,支持传入反射类型、类型结构、类型结构数组
   */
  public function __construct(
    public string                           $name,
    public string                           $description,
    public bool                             $allowNull,
    public mixed                            $default,
    null|ReflectionType|TypeStructure|array $type
  )
  {
    if ($type instanceof TypeStructure) {
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
   * @return TypeStructure[]
   */
  private function parseTypes(
    null|ReflectionIntersectionType|ReflectionNamedType|ReflectionUnionType $type
  ): array
  {
    if (is_null($type)) {
      return [new TypeStructure('mixed', 'mixed', true)];
    }
    if ($type instanceof ReflectionIntersectionType) {
      $types = [new TypeStructure('object', 'object', true)];
    } elseif ($type instanceof ReflectionUnionType) {
      $types = array_map(function (ReflectionNamedType|ReflectionIntersectionType $typeItem) {
        if ($typeItem instanceof ReflectionIntersectionType) {
          return new TypeStructure('object', 'object', true);
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
   * @return TypeStructure
   */
  private function parseNamedType(ReflectionNamedType $type): TypeStructure
  {
    $isBuiltin = $type->isBuiltin();
    $name = $type->getName();
    // 如果不是内置类型 则判断其是否为类或枚举
    if (!$isBuiltin) {
      if (enum_exists($name)) {
        $structure = new EnumStructure($name);
        return new TypeStructure($structure->name, 'enum', false, $structure);
      } else {
        $structure = new ObjectStructure($name);
        return new TypeStructure(
          $structure->name, 'object', false, new ObjectStructure($name)
        );
      }
    } else {
      return new  TypeStructure($name, $name, true);
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

  /**
   * 提取类名
   *
   * @param string $class 完整的类名（包含命名空间）
   * @return string 只包含类名的部分
   * @throws InvalidArgumentException 如果传入的不是有效的类名字符串
   */
  private function extractClassName(string $class): string
  {
    $class = trim($class);
    if (empty($class)) {
      throw new InvalidArgumentException('Class name cannot be empty.');
    }
    // 没有命名空间 直接返回类
    if (!str_contains($class, '\\')) return $class;
    // 使用PHP内置函数获取类名
    $className = basename(str_replace('\\', '/', $class));
    if ($className === '') {
      throw new InvalidArgumentException('Invalid class name provided.');
    }
    return $className;
  }
}
