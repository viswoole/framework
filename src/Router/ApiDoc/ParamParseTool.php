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
use ReflectionType;
use RuntimeException;
use Viswoole\Router\ApiDoc\Annotation\IgnoreGlobal;
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
    $reflector = self::toReflector($handler);
    // 解析全局参数排除规则（类级+方法级），先于全局配置读取以支持过滤
    $ignoreRules = self::parseIgnoreRules($reflector);
    // 读取全局配置并应用排除规则
    $globalBody = self::filterGlobalFields(
      config('router.api_doc.body', []), IgnoreGlobal::SOURCE_BODY, $ignoreRules
    );
    $globalHeader = self::filterGlobalFields(
      config('router.api_doc.header', []), IgnoreGlobal::SOURCE_HEADER, $ignoreRules
    );
    $globalQuery = self::filterGlobalFields(
      config('router.api_doc.query', []), IgnoreGlobal::SOURCE_QUERY, $ignoreRules
    );
    $globalReturned = self::filterGlobalReturned(
      config('router.api_doc.returned', []), $ignoreRules
    );
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
          // PHP 8.5 起 ReflectionMethod 单参构造弃用，须用 createFromMethodName
          $reflection = ReflectionMethod::createFromMethodName($callable);
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
   * 解析单个参数的来源与结构
   *
   * @param ReflectionParameter $parameter 反射参数
   * @param string $docComment 方法文档注释
   * @return array{body:FieldStructure[],query:FieldStructure[],header:FieldStructure[]}|null 无参数来源注解时返回 null
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
        // 文件参数归入body来源展示
        $source = 'body';
        $fieldType = self::parseFileType($type);
      } else {
        // docblock @param 类型声明优先于反射类型，
        // 用于补充反射无法表达的信息（如数组元素类型 array{id:int}、int[]）
        $docType = DocCommentTool::extractParamType($docComment, $name);
        $dependMap = [];
        $parsedType = $docType === '' ? [] : DocTypeParser::parse($docType, $dependMap);
        // 解析失败时回退到反射类型
        $fieldType = $parsedType === [] ? $type : $parsedType;
      }
      $params[$source][$name] = new FieldStructure(
        $name, $description, $allowNull, $default, $fieldType
      );
    }
    return $params;
  }

  /**
   * 解析文件上传参数的类型结构
   *
   * 参数类型包含 array 时视为多文件上传，包含 File 或未声明类型时视为单文件上传
   *
   * @param ReflectionType|null $type 反射参数类型
   * @return TypeStructure[] 类型结构列表
   */
  private static function parseFileType(?ReflectionType $type): array
  {
    $typeString = (string)$type;
    $types = [];
    // 如果类型当中包含了数组，则视为要求上传多个文件
    if (str_contains($typeString, 'array')) {
      $types[] = new ArrayTypeStructure(new TypeStructure(Types::File));
    }
    if (empty($types) || str_contains($typeString, 'File')) {
      // 否则视为上传单个文件
      $types[] = new TypeStructure(Types::File);
    }
    return $types;
  }

  /**
   * 解析处理方法上的全局参数排除规则
   *
   * 类级规则作用于控制器内所有路由方法，方法/函数级规则仅作用于自身，两者叠加生效
   *
   * @param ReflectionMethod|ReflectionFunction $reflector 处理方法反射
   * @return IgnoreGlobal[] 排除规则列表
   */
  private static function parseIgnoreRules(ReflectionMethod|ReflectionFunction $reflector): array
  {
    $rules = [];
    // 类级排除规则（如整个控制器无需鉴权头）
    if ($reflector instanceof ReflectionMethod) {
      foreach ($reflector->getDeclaringClass()->getAttributes(IgnoreGlobal::class) as $attribute) {
        $rules[] = $attribute->newInstance();
      }
    }
    // 方法/函数级排除规则
    foreach ($reflector->getAttributes(IgnoreGlobal::class) as $attribute) {
      $rules[] = $attribute->newInstance();
    }
    return $rules;
  }

  /**
   * 应用排除规则过滤全局请求参数
   *
   * @param array<string,mixed> $fields 全局参数列表，键为参数名
   * @param string $source 参数来源（header/query/body）
   * @param IgnoreGlobal[] $rules 排除规则列表
   * @return array<string,mixed> 过滤后的参数列表
   */
  private static function filterGlobalFields(array $fields, string $source, array $rules): array
  {
    if (empty($rules)) return $fields;
    foreach ($fields as $name => $field) {
      if (self::hitIgnoreRules($rules, $source, (string)$name)) {
        unset($fields[$name]);
      }
    }
    return $fields;
  }

  /**
   * 应用排除规则过滤全局返回声明
   *
   * @param Returned[] $returned 全局返回声明列表
   * @param IgnoreGlobal[] $rules 排除规则列表
   * @return Returned[] 过滤后的返回声明列表
   */
  private static function filterGlobalReturned(array $returned, array $rules): array
  {
    if (empty($rules)) return $returned;
    foreach ($returned as $index => $item) {
      // returned 的标识为标题，按标题匹配字段名规则
      $title = $item instanceof Returned ? $item->title : null;
      if (self::hitIgnoreRules($rules, IgnoreGlobal::SOURCE_RETURNED, $title)) {
        unset($returned[$index]);
      }
    }
    return array_values($returned);
  }

  /**
   * 判断指定来源与字段名是否命中任意一条排除规则
   *
   * @param IgnoreGlobal[] $rules 排除规则列表
   * @param string $source 参数来源
   * @param string|null $name 字段名或返回声明标题
   * @return bool 命中返回 true
   */
  private static function hitIgnoreRules(array $rules, string $source, ?string $name): bool
  {
    foreach ($rules as $rule) {
      if ($rule->matches($source, $name)) return true;
    }
    return false;
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
