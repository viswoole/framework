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

namespace Viswoole\Router;

use ArrayAccess;
use Closure;
use InvalidArgumentException;
use Override;
use RuntimeException;

/**
 * 路线配置类
 */
abstract class RouteConfig implements ArrayAccess
{
  /**
   * @var array 路由可选配置选项
   */
  protected array $options = [
    // 路由访问路径
    'paths' => null,
    // 路由描述
    'describe' => '',
    // 处理方法
    'handler' => null,
    // 请求方式
    'method' => ['*'],
    // 请求参数验证
    'params' => [],
    // 路由中间件
    'middleware' => [],
    // 伪静态后缀校验，例如html
    'suffix' => ['*'],
    // 域名路由
    'domain' => ['*'],
    // 变量正则表达式
    'pattern' => [],
    // 是否隐藏doc文档
    'hidden' => false
  ];

  /**
   * @param string|array $paths
   * @param callable|string|array $handler
   * @param array|null $parentOption
   */
  public function __construct(
    string|array          $paths,
    callable|string|array $handler,
    array                 $parentOption = null
  )
  {
    if (is_array($parentOption)) {
      $this->options = $parentOption;
      $this->options['describe'] = '';
    } else {
      $this->suffix(config('router.suffix', ['*']));
      $this->domain(config('router.domain', []));
    }
    $this->paths($paths);
    $this->handler($handler);
  }

  /**
   * 设置伪静态扩展
   * @param string|array $suffix *代表允许所有后缀
   * @return $this
   */
  public function suffix(string|array $suffix): static
  {
    $this->options['suffix'] = is_string($suffix) ? [$suffix] : $suffix;
    return $this;
  }

  /**
   * 设置域名检测
   *
   * @param string|array $domains
   * @return static
   */
  public function domain(string|array $domains): static
  {
    $domains = is_string($domains) ? [$domains] : $domains;
    $this->options['domain'] = $domains;
    return $this;
  }

  /**
   * 路由path
   *
   * @param string|array $paths
   * @return void
   */
  protected function paths(string|array $paths): void
  {
    $case = config('router.case_sensitive', false);
    if (is_string($paths)) $paths = [$paths];
    $pattern = [];
    foreach ($paths as &$path) {
      if (RouteCollector::isVariable($path)) {
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
          if (RouteCollector::isVariable($segment)) {
            $name = RouteCollector::extractVariableName($segment);
            $pattern[$name] = $this->options['pattern'][$name]
              ?? config('route.default_pattern_regex', '\w+');
          }
        }
      }
      if (!str_starts_with($path, '/')) $path = "/$path";
      $path = $path === '/' ? '/' : rtrim(!$case ? strtolower($path) : $path, '/');
    }
    $this->pattern($pattern);
    // 合并父级path
    if (!empty($this->options['paths'])) {
      $mergePaths = [];
      foreach ($this->options['paths'] as $path1) {
        foreach ($paths as $path2) {
          if ($path2 === '/') {
            $mergePaths[] = $path1;
          } else {
            $path1 = $path1 === '/' ? '' : $path1;
            $mergePaths[] = $path1 . $path2;
          }
        }
      }
      $this->options['paths'] = $mergePaths;
    } else {
      $this->options['paths'] = $paths;
    }
  }

  /**
   * 变量规则
   * @param array $pattern ['name'=>pattern]
   * @return RouteConfig
   */
  public function pattern(array $pattern): static
  {
    $this->options['pattern'] = $pattern;
    return $this;
  }

  /**
   * 路由处理
   *
   * @param callable|string|array $handler
   * @return void
   */
  protected function handler(callable|string|array $handler): void
  {
    if (is_string($handler) && str_contains($handler, '@')) {
      $handler = explode('@', $handler);
    }
    if (!is_array($handler) && !is_callable($handler)) {
      throw new InvalidArgumentException(
        '路由handler配置错误，需给定class::method|class@method|[class|object,method]|Closure'
      );
    }
    // [类=>方法] | 闭包
    $this->options['handler'] = $handler;
    // 请求参数
    $this->options['params'] = ShapeTool::getParamTypeShape($handler);
  }

  /**
   * 请求方法
   *
   * @param string|array[] $method
   * @return static
   */
  public function method(string|array $method = '*'): static
  {
    if (is_array($method)) {
      $this->options['method'] = array_map('strtoupper', $method);
    } else {
      $this->options['method'] = explode(',', strtoupper($method));
    }
    return $this;
  }

  /**
   * 获取配置
   *
   * @return array{
   *   paths: string[],
   *   describe: string,
   *   handler: callable,
   *   method: string[],
   *   params: array,
   *   middleware: array,
   *   suffix: string[],
   *   domain: string[],
   *   hidden: bool,
   * }
   */
  public function getOptions(): array
  {
    return $this->options;
  }

  /**
   * 路由描述
   *
   * @param string $describe 描述
   * @return static
   */
  public function describe(string $describe): static
  {
    $this->options['describe'] = $describe;
    return $this;
  }

  /**
   * 设置路由中间件
   * @access public
   * @param string|Closure|array{string|Closure} $middleware middleware::class | Closure
   * @return static
   */
  public function middleware(string|Closure|array $middleware): static
  {
    if (!is_array($middleware)) {
      $middleware = [$middleware];
    }
    $this->options['middleware'] = array_merge($this->options['middleware'], $middleware);
    return $this;
  }

  /**
   * 是否隐藏路由文档
   *
   * @param bool $hide
   * @return static
   */
  public function hidden(bool $hide): static
  {
    $this->options['hidden'] = $hide;
    return $this;
  }

  /**
   * 注册路由
   *
   * @param RouteCollector $collector 当前路线收集器实例
   * @return void
   */
  abstract public function register(RouteCollector $collector): void;

  /**
   * 批量设置选项
   *
   * @param array $options
   * @return RouteConfig
   */
  public function options(array $options): static
  {
    foreach ($options as $key => $value) {
      if (method_exists($this, $key)) {
        $this->$key($value);
      } else {
        trigger_error("不存在{$key}路由选项", E_USER_WARNING);
      }
    }
    return $this;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return array_key_exists($offset, $this->options);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    return $this->options[$offset] ?? null;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    throw new RuntimeException('Router option is read-only.');
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    throw new RuntimeException('Router option is read-only.');
  }

  /**
   * 获取api结构
   *
   * @return array{
   *    paths: string[],
   *    describe: string,
   *    method:string[],
   *    params:array<int,array{name:string,type:string,required:bool,default:mixed,describe:string,depend:bool,variadic:bool}>,
   *    suffix:string[],
   *    domain:string[],
   *    pattern:array<string,string>,
   *    children:array,
   *  }|null 如果返回null则是隐藏
   */
  public function getShape(): ?array
  {
    if ($this->options['hidden']) return null;
    $docShape = [
      // 路由路径
      'paths' => $this->options['paths'],
      // 路由描述
      'describe' => $this->options['describe'],
      // 请求方式
      'method' => $this->options['method'],
      // 请求参数验证
      'params' => $this->options['params'],
      // 伪静态后缀校验，例如html
      'suffix' => $this->options['suffix'],
      // 域名路由
      'domain' => $this->options['domain'],
      // 变量正则表达式
      'pattern' => $this->options['pattern'],
      // 子路由
      'children' => []
    ];
    $docShape['params'] = array_filter($docShape['params'], function ($param) {
      return !$param['depend'];
    });
    if ($this instanceof RouteGroup) {
      $children = [];
      /**
       * @var RouteConfig $item
       */
      foreach ($this->items as $item) {
        $item = $item->getShape();
        if (is_array($item)) {
          $children[] = $item;
        }
      }
      $docShape['children'] = $children;
    }
    return $docShape;
  }

  /**
   * 请求参数
   *
   * @param array $params shape
   * @return static
   */
  protected function params(array $params): static
  {
    $this->options['params'] = array_merge($this->options['params'], $params);
    return $this;
  }
}
