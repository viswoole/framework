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

use Closure;
use JsonSerializable;
use ReflectionAttribute;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Viswoole\Router\Annotation\Returned;
use Viswoole\Router\ApiDoc\DocCommentTool;
use Viswoole\Router\ApiDoc\ParamSourceInterface\BaseSourceInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\BodyParamInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\FileParamInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\HeaderParamInterface;
use Viswoole\Router\ApiDoc\ParamSourceInterface\QueryParamInterface;

/**
 * 请求参数结构
 */
class ApiStructure implements JsonSerializable
{
  /**
   * @var FieldStructure[] 文件请求参数
   */
  public array $file = [];
  /**
   * @var FieldStructure[] body请求参数
   */
  public array $body = [];
  /**
   * @var FieldStructure[] GET查询参数
   */
  public array $query = [];
  /**
   * @var FieldStructure[] 请求头参数
   */
  public array $header = [];
  /**
   * @var Returned[] 返回值列表
   */
  public array $returned = [];

  /**
   * 解析请求处理函数
   *
   * @param callable $handle 请求处理函数
   */
  public function __construct(callable $handle)
  {
    $reflector = $this->toReflector($handle);
    // 参数列表
    $parameters = $reflector->getParameters();
    // 文档注释
    $docComment = $reflector->getDocComment() ?: '';
    // 解析参数结构
    foreach ($parameters as $parameter) {
      $this->parseParamField($parameter, $docComment);
    }
    // 获取返回值注解属性
    $returnedAttributes = $reflector->getAttributes(Returned::class);
    // 解析返回值
    foreach ($returnedAttributes as $item) {
      $this->returned[] = $item->newInstance();
    }
  }

  /**
   * 将处理方法转换为反射对象
   *
   * @param array|callable $callable
   * @return ReflectionMethod|ReflectionFunction
   */
  private function toReflector(array|callable $callable): ReflectionMethod|ReflectionFunction
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
   * @return void
   */
  private function parseParamField(
    ReflectionParameter $parameter,
    string              $docComment
  ): void
  {
    $preInjects = $parameter->getAttributes(
      BaseSourceInterface::class, ReflectionAttribute::IS_INSTANCEOF
    );
    // 如果没有注解 则直接返回
    if (empty($preInjects)) return;
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
    foreach ($preInjects as $inject) {
      $instance = $inject->newInstance();
      // 参数来源
      $source = $this->parseParamSource($instance);
      if ($source === 'file') {
        $typeString = (string)$type;
        $type = [];
        // 如果类型当中包含了数组，则视为要求上传多个文件
        if (str_contains($typeString, 'array')) {
          $type[] = new ArrayTypeStructure(new BaseTypeStructure('File'));
        } elseif (str_contains($typeString, 'File')) {
          // 否则视为上传单个文件
          $type[] = new BaseTypeStructure('File');
        }
      }
      $this->{$source}[] = new FieldStructure($name, $description, $allowNull, $default, $type);
    }
  }

  /**
   * 参数来源类型
   *
   * @param BaseSourceInterface $instance
   * @return string
   */
  private function parseParamSource(BaseSourceInterface $instance): string
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

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return [
      'file' => $this->file,
      'body' => $this->body,
      'query' => $this->query,
      'header' => $this->header,
    ];
  }
}
