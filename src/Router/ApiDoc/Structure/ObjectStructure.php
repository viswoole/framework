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
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionProperty;
use Viswoole\Router\ApiDoc\DocCommentTool;

/**
 * 对象结构
 */
class ObjectStructure extends ClassTypeStructure
{
  /**
   * @var FieldStructure[] 属性列表
   */
  public array $properties = [];

  /**
   * 构建对象结构
   *
   * @param string|object|array $classOrInstance 类名或实例，支持传入FieldStructure[]
   * @param bool $parseProperties 是否解析公开属性
   */
  public function __construct(string|object|array $classOrInstance, bool $parseProperties = false)
  {
    parent::__construct('object');
    if (is_array($classOrInstance)) {
      $this->addProperty($classOrInstance);
    } else {
      try {
        $reflector = new ReflectionClass($classOrInstance);
      } catch (ReflectionException $e) {
        throw new InvalidArgumentException(
          '必须是类名或实例：' . $e->getMessage(), $e->getCode(), $e
        );
      }
      $this->description = DocCommentTool::extractDocTitle($reflector->getDocComment() ?: '');
      $this->namespace = $reflector->getNamespaceName();
      $this->name = $reflector->getShortName();
      if (!$parseProperties) {
        // ReflectionParameter[] 获取构造函数参数
        $parameters = $reflector->getConstructor()?->getParameters();
        if (empty($parameters)) return;
        $docComment = $reflector->getConstructor()?->getDocComment() ?: '';
        $this->parseParams($parameters, $docComment);
      } else {
        $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);
        $this->parseProperties($properties);
      }
    }
  }

  /**
   * 添加属性描述
   *
   * @param FieldStructure|FieldStructure[] $fieldStructure
   * @return $this
   */
  public function addProperty(FieldStructure|array $fieldStructure): static
  {
    if (is_array($fieldStructure)) {
      foreach ($fieldStructure as $item) $this->addProperty($item);
    } elseif ($fieldStructure instanceof FieldStructure) {
      $this->properties[] = $fieldStructure;
    } else {
      throw new InvalidArgumentException(
        '$fieldStructure must be instance of ' . FieldStructure::class
      );
    }
    return $this;
  }

  /**
   * 解析类构造参数
   *
   * @param ReflectionParameter[] $parameters 参数列表
   * @param string $docComment 构造函数文档注释
   * @return void
   */
  private function parseParams(array $parameters, string $docComment): void
  {
    foreach ($parameters as $parameter) {
      $name = $parameter->getName();
      $item = new FieldStructure(
        $name,
        DocCommentTool::extractParamDoc($docComment, $name),
        $parameter->allowsNull(),
        $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
        $parameter->getType()
      );
      $this->properties[] = $item;
    }
  }

  /**
   * 解析类构造参数
   *
   * @param ReflectionProperty[] $properties 参数列表
   * @return void
   */
  private function parseProperties(array $properties): void
  {
    foreach ($properties as $item) {
      $name = $item->getName();
      $type = $item->getType();
      $item = new FieldStructure(
        $name,
        DocCommentTool::extractPropertyDoc($item->getDocComment() ?: ''),
        $type->allowsNull(),
        $item->getDefaultValue(),
        $type
      );
      $this->properties[] = $item;
    }
  }
}
