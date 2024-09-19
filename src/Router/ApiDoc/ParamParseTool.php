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

namespace Viswoole\Router\ApiDoc;

use Closure;
use ReflectionAttribute;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\ParamSourceInterface\BaseSourceInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\BodyParamInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\FileParamInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\HeaderParamInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\QueryParamInterface;
use Viswoole\Router\ApiDoc\Structure\ArrayTypeStructure;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\Types;
use Viswoole\Router\ApiDoc\Structure\TypeStructure;

/**
 * 请求参数与响应结构解析工具
 */
class ParamParseTool
{
  /**
   * 解析请求处理函数
   *
   * @param callable|array $handler
   * @return array{params:array{body:array<string,FieldStructure>, header:array<string,FieldStructure>, query:array<string,FieldStructure>}, returned:Returned[]}
   */
  public static function parse(callable|array $handler): array
  {
    $globalBody = config('router.api_doc.body', []);
    $globalHeader = config('router.api_doc.header', []);
    $globalQuery = config('router.api_doc.query', []);
    $globalReturned = config('router.api_doc.returned', []);
    $reflector = self::toReflector($handler);
    // 参数列表
    $parameters = $reflector->getParameters();
    // 文档注释
    $docComment = $reflector->getDocComment() ?: '';
    // 解析参数结构
    foreach ($parameters as $parameter) {
      $params = self::parseParamField($parameter, $docComment);
      if (is_null($params)) continue;
      foreach ($params as $source => $fields) {
        switch ($source) {
          case 'body':
            $globalBody = array_merge($globalBody, $fields);
            break;
          case 'query':
            $globalQuery = array_merge($globalQuery, $fields);
            break;
          case 'header':
            $globalHeader = array_merge($globalHeader, $fields);
            break;
        }
      }
    }
    // 获取返回值注解属性
    $returnedAttributes = $reflector->getAttributes(Returned::class);
    // 解析返回值
    foreach ($returnedAttributes as $item) {
      $globalReturned[] = $item->newInstance();
    }
    // 排序
    usort($globalReturned, function (Returned $a, Returned $b) {
      return $b->sort <=> $a->sort;
    });
    return [
      'params' => [
        'body' => $globalBody,// body参数
        'header' => $globalHeader,// header参数
        'query' => $globalQuery, // query参数
      ],
      'returned' => $globalReturned // 返回值列表
    ];
  }

  /**
   * 将处理方法转换为反射对象
   *
   * @param array|callable $callable
   * @return ReflectionMethod|ReflectionFunction
   */
  private static function toReflector(array|callable $callable): ReflectionMethod|ReflectionFunction
  {
    try {
      if ($callable instanceof Closure) {
        $reflection = new ReflectionFunction($callable);
      } elseif (is_string($callable)) {
        if (str_contains($callable, '::')) {
          $reflection = new ReflectionMethod($callable);
        } else {
          $reflection = new ReflectionFunction($callable);
        }
      } else {
        $reflection = new ReflectionMethod($callable[0], $callable[1]);
      }
      return $reflection;
    } catch (ReflectionException $e) {
      throw new RuntimeException('处理方法解析失败:' . $e->getMessage(), previous: $e);
    }
  }

  /**
   * 解析参数结构
   *
   * @param ReflectionParameter $parameter
   * @param string $docComment
   * @return array{body:FieldStructure[], query:FieldStructure[], header:FieldStructure[]}|null
   */
  private static function parseParamField(
    ReflectionParameter $parameter,
    string              $docComment
  ): ?array
  {
    $preInjects = $parameter->getAttributes(
      BaseSourceInterface::class, ReflectionAttribute::IS_INSTANCEOF
    );
    // 如果没有注解 则直接返回
    if (empty($preInjects)) return null;
    // 参数名称
    $name = $parameter->getName();
    // 参数描述
    $description = DocCommentTool::extractParamDoc($docComment, $name);
    // 允许为null
    $allowNull = $parameter->allowsNull();
    // 默认值
    $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
    // 参数类型
    $type = $parameter->getType();
    $params = [];
    foreach ($preInjects as $inject) {
      $instance = $inject->newInstance();
      // 参数来源
      $source = self::parseParamSource($instance);
      if ($source === 'file') {
        $source = 'body';
        $typeString = (string)$type;
        $type = [];
        // 如果类型当中包含了数组，则视为要求上传多个文件
        if (str_contains($typeString, 'array')) {
          $type[] = new ArrayTypeStructure(new TypeStructure(Types::File));
        }
        if (empty($type) || str_contains($typeString, 'File')) {
          // 否则视为上传单个文件
          $type[] = new TypeStructure(Types::File);
        }
      }
      $params[$source][$name] = new FieldStructure(
        $name, $description, $allowNull, $default, $type
      );
    }
    return $params;
  }

  /**
   * 参数来源类型
   *
   * @param BaseSourceInterface $instance
   * @return string
   */
  private static function parseParamSource(BaseSourceInterface $instance): string
  {
    $sources = [
      'query' => QueryParamInterface::class,
      'body' => BodyParamInterface::class,
      'header' => HeaderParamInterface::class,
      'file' => FileParamInterface::class,
    ];
    foreach ($sources as $source => $interface) {
      if ($instance instanceof $interface) return $source;
    }
    // 如果都没有匹配 则默认为body
    return 'body';
  }
}
