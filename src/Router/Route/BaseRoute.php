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

namespace Viswoole\Router\Route;

use InvalidArgumentException;
use Viswoole\Core\App;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Middleware;
use Viswoole\Router\RouterTool;

/**
 * 路由基类，封装路径、方法、中间件、域名、后缀等通用配置
 *
 * 所有路由项（Route）和路由组（Group）共享此基类，
 * 支持从父级路由继承配置，并通过引用链路建立层级关系。
 *
 * @see Route 路由项
 * @see Group 路由组
 */
abstract class BaseRoute
{
  use ApiDoc;

  /**
   * @var mixed 处理函数
   */
  protected mixed $handler;
  /**
   * @var string 当前路由id
   */
  private readonly string $id;
  /**
   * @var ?string 父级路由id
   */
  private ?string $parentId;
  /**
   * @var array 访问路径
   */
  private array $paths;
  /**
   * @var array 请求方式
   */
  private array $method;
  /**
   * @var array 中间件列表
   */
  private array $middlewares = [];
  /**
   * @var array 动态路径变量正则表达式
   */
  private array $patterns;
  /**
   * @var array 其他元数据
   */
  private array $meta = [];
  /**
   * @var array 后缀校验
   */
  private array $suffix;
  /**
   * @var array 域名校验
   */
  private array $domain;

  /**
   * @param string|array $paths 路由访问路径
   * @param callable|string|array $handler 路由处理函数
   * @param BaseRoute|null $parentOption 父级路由配置
   * @param string|null $id
   */
  public function __construct(
    string|array          $paths,
    callable|string|array $handler,
    ?BaseRoute            $parentOption = null,
    ?string               $id = null,
  )
  {
    if ($parentOption) {
      // 继承父级配置
      $this->parentId = $parentOption->getCiteLink();
      $this->middlewares = $parentOption->middlewares;
      $this->meta = $parentOption->meta;
      $this->suffix = $parentOption->suffix;
      $this->domain = $parentOption->domain;
      $this->method = $parentOption->method;
      $this->paths = $parentOption->paths;
    } else {
      // 默认配置
      $this->parentId = null;
      $defaultMethod = config('router.method', ['*']);
      $this->setMethod(...(is_string($defaultMethod) ? [$defaultMethod] : $defaultMethod));
      $defaultSuffix = config('router.suffix', ['*']);
      $this->setSuffix(...(is_string($defaultSuffix) ? [$defaultSuffix] : $defaultSuffix));
      $defaultDomain = config('router.domain', ['*']);
      $this->setDomain(...(is_string($defaultDomain) ? [$defaultDomain] : $defaultDomain));
    }
    [$paths, $pattern] = $this->handelPaths($paths);
    // 路由path
    $this->paths = $paths;
    // 路由正则
    $this->patterns = $pattern;
    // 生成路由id
    $this->id = $id ?? $this->generateId();
    // 处理函数
    $this->handler = $this->verifyHandler($handler);
  }

  /**
   * 获取从根到当前路由的完整引用链路（以点号分隔的 ID 路径）
   *
   * @return string|null 引用链路，无父级时等于自身 ID
   */
  public function getCiteLink(): ?string
  {
    if (empty($this->getParentId())) {
      return $this->id;
    } else {
      return $this->getParentId() . '.' . $this->id;
    }
  }

  /**
   * 获取父级路由ID
   *
   * @return ?string 父级路由ID
   */
  public function getParentId(): ?string
  {
    return $this->parentId;
  }

  /**
   * 设置父级路由 ID
   *
   * @param string|null $parentId 父级路由 ID
   * @return $this
   */
  public function setParentId(?string $parentId): static
  {
    $this->parentId = $parentId;
    return $this;
  }

  /**
   * 解析并规范化路径列表，提取动态变量正则约束
   *
   * 处理路径前缀补全、大小写转换、与父级路径合并，并从路径中提取动态变量约束。
   *
   * @param string|array $paths 原始路径
   * @return array{paths:array,pattern:array} [0=>规范化后的路径列表, 1=>变量名到正则的映射]
   */
  private function handelPaths(string|array $paths): array
  {
    $default_pattern_regex = config('router.default_pattern_regex', '\w+');
    $case = config('router.case_sensitive', false);
    if (is_string($paths)) $paths = [$paths];
    foreach ($paths as &$path) {
      if (!str_starts_with($path, '/')) $path = "/$path";
      $path = $path === '/' ? '/' : rtrim(!$case ? strtolower($path) : $path, '/');
      // 去除所有空格
      $path = str_replace(' ', '', $path);
    }
    // 合并父级path
    if (!empty($this->paths)) {
      $mergePaths = [];
      foreach ($this->paths as $path1) {
        foreach ($paths as $path2) {
          if ($path2 === '/') {
            $mergePaths[] = $path1;
          } else {
            $path1 = $path1 === '/' ? '' : $path1;
            $mergePaths[] = $path1 . $path2;
          }
        }
      }
      $paths = $mergePaths;
    }
    unset($path);
    $pattern = [];
    foreach ($paths as $path) {
      if (RouterTool::isVariable($path)) {
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
          if (empty($segment)) continue;
          if (RouterTool::isVariable($segment)) {
            $name = RouterTool::extractVariableName($segment);
            $pattern[$name] = $this->patterns[$name] ?? $default_pattern_regex;
          }
        }
      }
    }
    return [$paths, $pattern];
  }

  /**
   * 生成唯一id
   *
   * @return string
   */
  private function generateId(): string
  {
    $id = implode('&', $this->paths);
    return RouterTool::generateHashId($id);
  }

  /**
   * 验证路由处理程序
   *
   * @param callable|string|array $handler
   * @return callable|array
   */
  protected function verifyHandler(callable|string|array $handler): callable|array
  {
    if (is_string($handler) && str_contains($handler, '@')) {
      $handler = explode('@', $handler);
    }
    if (!App::isCallable($handler)) {
      throw new InvalidArgumentException('Invalid handler');
    }
    // [类=>方法] | 闭包
    return $handler;
  }

  /**
   * 获取路由处理函数
   *
   * @return callable|array 处理函数
   */
  public function getHandler(): callable|array
  {
    return $this->handler;
  }

  /**
   * 获取访问路径列表
   *
   * @return array 路径列表
   */
  public function getPaths(): array
  {
    return $this->paths;
  }

  /**
   * 获取路由ID
   *
   * @return string
   */
  public function getId(): string
  {
    return $this->id;
  }

  /**
   * 获取请求方式
   *
   * @return array 请求方式
   */
  public function getMethod(): array
  {
    return $this->method;
  }

  /**
   * 设置请求方式
   *
   * @param string ...$method 自动转换为大写
   * @return $this
   */
  public function setMethod(string ...$method): static
  {
    array_walk($method, function (&$value) {
      $value = strtoupper(trim($value));
    });
    $this->method = $method;
    return $this;
  }

  /**
   * 获取中间件列表
   *
   * @return array 中间件列表
   */
  public function getMiddlewares(): array
  {
    return $this->middlewares;
  }

  /**
   * 追加中间件到列表，校验每个中间件的有效性
   *
   * @param array<callable|string|array> $middlewares 中间件列表
   * @return $this
   * @throws InvalidArgumentException 中间件格式无效时抛出
   */
  public function setMiddlewares(array $middlewares): static
  {
    foreach ($middlewares as $index => $handle) {
      try {
        Middleware::checkMiddleware($handle);
      } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException(
          "Invalid middleware handle at index $index: " . $e->getMessage()
        );
      }
    }
    $this->middlewares = array_merge($this->middlewares, $middlewares);
    return $this;
  }

  /**
   * 获取动态路径变量的正则约束映射
   *
   * @return array 变量名到正则的映射
   */
  public function getPatterns(): array
  {
    return $this->patterns;
  }

  /**
   * 追加动态路径变量的正则约束，校验每个正则的有效性
   *
   * @param array<string,string> $patterns 变量名到正则的映射
   * @return $this
   * @throws InvalidArgumentException 正则为空或语法错误时抛出
   */
  public function setPatterns(array $patterns): static
  {
    foreach ($patterns as $name => $regex) {
      if (!is_string($regex) || empty($regex)) {
        throw new InvalidArgumentException("路由参数正则 '$name' 必须是非空字符串");
      }
      // 验证正则表达式是否有效
      if (@preg_match('/' . $regex . '/', '') === false) {
        throw new InvalidArgumentException("路由参数正则 '$name' 无效: $regex");
      }
    }
    $this->patterns = array_merge($this->patterns, $patterns);
    return $this;
  }

  /**
   * 获取其他元数据
   *
   * @return array<string,string> 其他元数据
   */
  public function getMeta(): array
  {
    return $this->meta;
  }

  /**
   * 设置其他元数据
   *
   * @param array $meta
   * @return $this
   */
  public function setMeta(array $meta): static
  {
    if (!Arr::isAssociativeArray($meta, true)) {
      throw new InvalidArgumentException('Invalid meta data type must be an associative array');
    }
    $this->meta = array_merge($this->meta, $meta);
    return $this;
  }

  /**
   * 获取允许的伪静态后缀列表
   *
   * @return array 后缀列表
   */
  public function getSuffix(): array
  {
    return $this->suffix;
  }

  /**
   * 设置后缀校验
   *
   * @param string ...$suffix
   * @return $this
   */
  public function setSuffix(string ...$suffix): static
  {
    $this->suffix = $suffix;
    return $this;
  }

  /**
   * 获取域名校验
   *
   * @return array 域名校验
   */
  public function getDomain(): array
  {
    return $this->domain;
  }

  /**
   * 设置允许的域名列表
   *
   * @param string ...$domain 域名
   * @return $this
   */
  public function setDomain(string ...$domain): static
  {
    $this->domain = $domain;
    return $this;
  }
}
