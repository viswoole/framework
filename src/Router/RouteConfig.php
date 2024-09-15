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
use InvalidArgumentException;
use Override;
use RuntimeException;
use Viswoole\Core\App;
use Viswoole\Core\Common\Arr;

/**
 * 路线配置类
 *
 * @property string|null $parent 父级分组路由id
 * @property string $id 路由唯一id
 * @property string $title 路由标题
 * @property array $paths 路由路径
 * @property string $description 路由描述
 * @property callable $handler 路由处理方法
 * @property string[] $method 请求方式
 * @property array $middleware 路由中间件
 * @property string[] $suffix 伪静态后缀
 * @property string[] $domain 域名
 * @property array $pattern 变量正则表达式
 * @property bool $hidden 是否隐藏在文档中隐藏
 * @property int $sort 路由排序
 * @property array $meta 额外的元数据
 */
abstract class RouteConfig implements ArrayAccess
{
  /**
   * @var array 路由可选配置选项
   */
  protected array $options = [
    // 父级分组路由id
    'parent' => null,
    // 路由唯一id
    'id' => null,
    // 路由访问路径
    'paths' => null,
    // 标题
    'title' => '',
    // 路由描述
    'description' => '',
    // 处理方法
    'handler' => null,
    // 请求方式
    'method' => ['*'],
    // 中间件列表
    'middleware' => [],
    // 伪静态后缀校验，例如html
    'suffix' => ['*'],
    // 域名路由
    'domain' => ['*'],
    // 变量正则表达式
    'pattern' => [],
    // 是否隐藏在文档中隐藏
    'hidden' => false,
    // 文档排序,值越大越靠前
    'sort' => 0,
    // 额外的元数据
    'meta' => [],
  ];

  /**
   * @param string|array $paths
   * @param callable|string|array $handler
   * @param string|null $id 自定义路由id
   * @param array|null $parentOption
   */
  public function __construct(
    string|array          $paths,
    callable|string|array $handler,
    array                 $parentOption = null,
    string                $id = null,
  )
  {
    if (is_array($parentOption)) {
      $parentOption['sort'] = 0;
      $this->options = $parentOption;
      $this->options['description'] = '';
    } else {
      $this->suffix(config('router.suffix', ['*']));
      $this->domain(config('router.domain', []));
      $this->domain(config('router.method', ['*']));
    }
    $this->paths($paths);
    $this->handler($handler);
    $this->id($id ?? $this->generateId());
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
    if (!App::isCallable($handler)) {
      throw new InvalidArgumentException('Invalid handler');
    }
    // [类=>方法] | 闭包
    $this->options['handler'] = $handler;
  }

  /**
   * 路由唯一id
   *
   * 无需手动设置，框架会对每个路由项生成一个尽可能不变且唯一的id
   *
   * @param string $id
   * @return static
   */
  public function id(string $id): static
  {
    $this->options['id'] = $id;
    return $this;
  }

  /**
   * 生成唯一id
   *
   * @return string
   */
  private function generateId(): string
  {
    $id = implode('&', $this->options['paths']);
    return Route::generateHashId($id);
  }

  /**
   * 路由标题
   *
   * @param string $title
   * @return static
   */
  public function title(string $title): static
  {
    $this->options['title'] = $title;
    return $this;
  }

  /**
   * 自定义的元数据
   *
   * @access public
   * @param string $key 键
   * @param mixed $value 值，必须是可序列化的变量。
   * @return static
   */
  public function meta(string $key, mixed $value): static
  {
    $this->options['mate'][$key] = $value;
    return $this;
  }

  /**
   * 设置排序值
   *
   * @param int $sort 越大越靠前
   * @return $this
   */
  public function sort(int $sort): static
  {
    $this->options['sort'] = $sort;
    return $this;
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
   *   description: string,
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
   * @param string $description 描述
   * @return static
   */
  public function description(string $description): static
  {
    $this->options['description'] = $description;
    return $this;
  }

  /**
   * 设置路由中间件
   *
   * @access public
   * @param array $middlewares 中间件
   * @return static
   */
  public function middleware(array $middlewares): static
  {
    $this->options['middleware'] = array_merge($this->options['middleware'], $middlewares);
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
   * @deprecated 外部调用警告！
   */
  abstract public function _register(RouteCollector $collector): void;

  /**
   * 批量设置选项
   *
   * @param array{middleware:array,suffix:string[],domain:string[],pattern:array,hidden:bool,sort:int,meta:array} $options
   * @return static
   */
  public function options(array $options): static
  {
    foreach ($options as $key => $value) {
      if (is_int($key)) continue;
      if ($key === 'handler') continue;
      if ($key === 'meta') {
        if (Arr::isAssociativeArray($value)) {
          $this->options['meta'] = array_merge($this->options['meta'], $value);
        } else {
          throw new InvalidArgumentException('路由meta配置错误，需给定键值对数组');
        }
      } elseif (method_exists($this, $key)) {
        $this->$key($value);
      } else {
        trigger_error("不存在{$key}路由配置", E_USER_WARNING);
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
   * @param string $name
   * @return mixed
   */
  public function __get(string $name)
  {
    return $this->offsetGet($name);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    return $this->options[$offset] ?? null;
  }

  /**
   * 获取完整的引用链路，包含当前路由id
   *
   * @return string
   */
  public function getCiteLink(): string
  {
    if (empty($this->options['parent'])) {
      return $this->options['id'];
    } else {
      return $this->options['parent'] . '.' . $this->options['id'];
    }
  }
}
