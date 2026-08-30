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

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;
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
   * @var array{file:string, line:int}|null 源码位置，供接口文档定位
   */
  private ?array $source;
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
   * @param string[]|null $methods 请求方式列表，显式传入时覆盖默认/继承配置
   *        （必须在生成路由id前设置：同路径不同方法的配置路由依赖方法维度生成唯一id）
   */
  public function __construct(
    string|array          $paths,
    callable|string|array $handler,
    ?BaseRoute            $parentOption = null,
    ?string               $id = null,
    ?array                $methods = null,
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
    // 显式声明的请求方式优先于默认/继承配置
    if (!empty($methods)) $this->setMethod(...$methods);
    [$paths, $pattern] = $this->handlePaths($paths);
    // 路由path
    $this->paths = $paths;
    // 路由正则
    $this->patterns = $pattern;
    // 生成路由id
    $this->id = $id ?? $this->generateId();
    // 处理函数
    $this->handler = $this->verifyHandler($handler);
    // 从处理函数自动推断源码位置，供接口文档定位
    $this->source = $this->resolveSourceLocation($this->handler);
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
   * 父子路径合并规则：
   * - 不以 / 开头的子路径视为相对路径：与父级路径做笛卡尔积拼接
   * - 以 / 开头的子路径视为绝对路径：忽略父级前缀，直接作为根路由
   * - 单独的 / 仍表示父路由本身路径（组默认入口）
   *
   * @param string|array $paths 原始路径
   * @return array{0:array,1:array} [0=>规范化后的路径列表, 1=>变量名到正则的映射]
   */
  private function handlePaths(string|array $paths): array
  {
    $default_pattern_regex = config('router.default_pattern_regex', '\w+');
    $case = config('router.case_sensitive', false);
    if (is_string($paths)) $paths = [$paths];
    // 必须在补全 / 前记录原始路径是否以 / 开头，否则无法区分绝对/相对路径
    $absoluteFlags = [];
    foreach ($paths as $key => $path) {
      $absoluteFlags[$key] = is_string($path) && str_starts_with($path, '/');
    }
    foreach ($paths as &$path) {
      if (!str_starts_with($path, '/')) $path = "/$path";
      // 静态段：URL 解码（与 dispatch 请求侧逐段解码对齐，支持中文/空格等编码静态段）
      // + 小写化（case_sensitive=false 时）；变量段保留原始大小写：变量名需与控制器
      // 方法参数名、setPatterns 约束键对齐，整体小写会导致 {userId} 退化为 {userid}，
      // dispatch 提取的参数键与命名注入参数名不一致而注入失败
      $segments = explode('/', $path);
      foreach ($segments as &$segment) {
        if ($segment === '' || RouterTool::isVariable($segment)) continue;
        if (str_contains($segment, '%')) {
          $decoded = rawurldecode($segment);
          // 解码引入 / 的段（如 %2F）保留编码原样，防止注册路径段结构被重写
          // （dispatch 请求侧 decodeRequestPath 同规则，两侧对齐）
          if (!str_contains($decoded, '/')) $segment = $decoded;
        }
        if (!$case) $segment = strtolower($segment);
      }
      unset($segment);
      $path = implode('/', $segments);
      $path = $path === '/' ? '/' : rtrim($path, '/');
      // 去除所有空格
      $path = str_replace(' ', '', $path);
    }
    unset($path);
    // 合并父级path
    if (!empty($this->paths)) {
      $mergePaths = $absolutePaths = [];
      foreach ($paths as $key => $path2) {
        // 以 / 开头的子路径（单独的 / 除外）视为绝对路径，不继承父级前缀
        if ($absoluteFlags[$key] && $path2 !== '/') {
          $absolutePaths[] = $path2;
          continue;
        }
        foreach ($this->paths as $path1) {
          if ($path2 === '/') {
            $mergePaths[] = $path1;
          } else {
            $path1 = $path1 === '/' ? '' : $path1;
            $mergePaths[] = $path1 . $path2;
          }
        }
      }
      $paths = array_merge($mergePaths, $absolutePaths);
    }
    $pattern = [];
    foreach ($paths as $path) {
      if (RouterTool::isVariable($path)) {
        $segments = explode('/', trim($path, '/'));
        // 记录当前路径内已出现的变量名，用于重复检测
        $seenNames = [];
        foreach ($segments as $segment) {
          if (empty($segment)) continue;
          if (RouterTool::isVariable($segment)) {
            $name = RouterTool::extractVariableName($segment);
            // 变量名将作为 PCRE 命名捕获组，必须以字母/下划线开头且仅含字母数字下划线，
            // 且不超过 32 字符（PCRE 命名组名长度上限）；同一路径内重复的变量名
            // 同样无法编译为命名组，与其让路由静默永不匹配，不如在注册期给出明确错误
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
              throw new InvalidArgumentException(
                "路由变量名 '$name' 非法（路径：{$path}），必须以字母或下划线开头，仅含字母、数字、下划线"
              );
            }
            if (strlen($name) > 32) {
              throw new InvalidArgumentException(
                "路由变量名 '$name' 非法（路径：{$path}），长度超过 PCRE 命名组上限 32 字符"
              );
            }
            if (isset($seenNames[$name])) {
              throw new InvalidArgumentException(
                "路径 '$path' 中变量名 '$name' 重复定义，请使用不同的变量名"
              );
            }
            $seenNames[$name] = true;
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
   * id 由路径与请求方式共同决定：同一允许不同请求方式重复定义路径
   * （如 GET /profile 与 PUT /profile），仅凭路径无法区分会产生 id 冲突
   *
   * @return string
   */
  private function generateId(): string
  {
    $id = implode('&', $this->paths) . '@' . implode(',', $this->method);
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
   * 从处理函数反射推断源码位置
   *
   * 支持闭包、[类,方法]、['对象',方法]、'类::方法'、函数名、可调用对象
   *
   * @param callable|array $handler 处理函数
   * @return array{file:string, line:int}|null 文件绝对路径与起始行号，无法确定时返回null
   */
  private function resolveSourceLocation(callable|array $handler): ?array
  {
    try {
      if ($handler instanceof Closure) {
        $ref = new ReflectionFunction($handler);
      } elseif (is_array($handler) && isset($handler[0], $handler[1])) {
        $class = is_object($handler[0]) ? get_class($handler[0]) : $handler[0];
        $ref = new ReflectionMethod($class, $handler[1]);
      } elseif (is_string($handler) && str_contains($handler, '::')) {
        [$class, $method] = explode('::', $handler, 2);
        $ref = new ReflectionMethod($class, $method);
      } elseif (is_string($handler) && function_exists($handler)) {
        $ref = new ReflectionFunction($handler);
      } elseif (is_object($handler)) {
        $ref = new ReflectionMethod($handler, '__invoke');
      } else {
        return null;
      }
      $file = $ref->getFileName();
      if (empty($file)) return null;
      // 转换为相对项目根目录的路径，便于文档跨环境（如容器内外）定位
      return ['file' => RouterTool::relativeToRoot($file), 'line' => $ref->getStartLine()];
    } catch (Throwable) {
      // 反射失败（类未加载、方法不存在等）时忽略，不影响路由注册
      return null;
    }
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
   * 获取源码位置
   *
   * @return array{file:string, line:int}|null 文件绝对路径与起始行号，无法确定时返回null
   */
  public function getSource(): ?array
  {
    return $this->source;
  }

  /**
   * 设置源码位置
   *
   * 用于处理函数为占位符（如控制器路由组）时显式指定源码位置
   *
   * @param string $file 文件绝对路径
   * @param int $line 起始行号
   * @return $this
   */
  public function setSourceLocation(string $file, int $line): static
  {
    $this->source = ['file' => $file, 'line' => $line];
    return $this;
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
   * 兼容两种传参形式：setMethod('GET', 'POST') 与 setMethod(['GET', 'POST'])
   * （注解 method 属性为数组，经 RouteAnnotation::create() 以单参数传入）
   *
   * @param string|array ...$method
   * @return $this
   */
  public function setMethod(string|array ...$method): static
  {
    $methods = [];
    foreach ($method as $item) {
      foreach ((array)$item as $value) {
        $methods[] = strtoupper(trim($value));
      }
    }
    $this->method = $methods;
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
      // 验证正则表达式是否有效（使用 # 分隔符：路径约束常含 [/] 语法如 [^/]+，
      // 若用 / 作分隔符会被误判为无效）
      if (@preg_match('#' . $regex . '#', '') === false) {
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
