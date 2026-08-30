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

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Viswoole\Core\App;
use Viswoole\Core\Config;
use Viswoole\Core\Event;
use Viswoole\Core\Middleware;
use Viswoole\Router\Annotation\AutoController;
use Viswoole\Router\Annotation\Controller;
use Viswoole\Router\Annotation\RouteMapping;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\ApiDocParseTool;
use Viswoole\Router\ApiDoc\DocCommentTool;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\Types;
use Viswoole\Router\Exception\RouteNotFoundException;
use Viswoole\Router\Route\BaseRoute;
use Viswoole\Router\Route\Collector;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由器，负责路由的收集、匹配与分发，支持路由缓存与 API 文档生成
 */
class Router extends Collector
{
  /**
   * @var array<string,array<string,string>> 完全静态路由映射
   *      键为 URL 路径，值为 [请求方法 => 路由引用链路]，
   *      同一路径允许按不同请求方法（GET/POST/...）注册多条路由
   */
  protected array $staticRoute = [];
  /**
   * @var array<string,array<string,array<string,string>>> 动态路由映射
   *      按路径段数分组（segment_N），第一层键为正则，值为 [请求方法 => 路由引用链路]，
   *      同一正则允许按不同请求方法注册多条路由
   */
  protected array $dynamicRoute = [];
  /**
   * @var bool 是否启用路由缓存
   */
  private bool $cache;
  /**
   * @var bool 是否启用 API 文档生成
   */
  private bool $enableApiDoc;
  /**
   * @var bool 路由是否已完成初始化
   */
  private bool $init;

  /**
   * 初始化路由器，加载配置路由与注解路由并构建路由树
   *
   * @param Event $event 事件管理器，用于触发路由初始化事件
   * @param Config $config 框架配置实例
   * @param Middleware $middleware 中间件调度器
   */
  public function __construct(
    private readonly Event      $event,
    private readonly Config     $config,
    private readonly Middleware $middleware
  )
  {
    App::factory()->bind(self::class, $this);
    // 是否缓存路由
    $this->cache = $config->get('router.cache.enable', false);
    // 是否生成api文档
    $this->enableApiDoc = $config->get('router.api_doc.enable', false);
    if ($this->enableApiDoc) {
      $this->verifyGlobalParams('router.api_doc.body');
      $this->verifyGlobalParams('router.api_doc.header');
      $this->verifyGlobalParams('router.api_doc.query');
      $this->verifyGlobalReturned();
    }
    // 触发路由初始化事件，其他模块可以监听该事件注册路由
    $this->event->emit('RouterInit');
    $this->loadConfigRoute();
    $this->loadAnnotationRoute();
    $this->parseRoute();
    $this->init = true;
    $this->event->emit('RouterInitialized');
  }

  /**
   * 验证全局参数，并返回新的参数列表
   *
   * @param string $name
   * @return void
   */
  private function verifyGlobalParams(string $name): void
  {
    $params = $this->config->get($name, []);
    if (empty($params)) return;
    if (!is_array($params)) {
      throw new InvalidArgumentException("$name 配置错误，必须是数组类型");
    }
    $newParams = [];
    foreach ($params as $index => $field) {
      $fieldStructure = $this->toFieldStructure($field, $name, $index);
      $newParams[$fieldStructure->name] = $fieldStructure;
    }
    $this->config->set($name, $newParams);
  }

  /**
   * 将全局参数配置项转换为字段结构实例
   *
   * 支持三种配置格式：
   * 1. FieldStructure 实例
   * 2. 极简格式：'参数名' => '参数描述'（类型默认 string）
   * 3. 关联数组：['name'=>..., 'description'=>..., 'allowNull'=>..., 'default'=>..., 'type'=>...]
   *    type 支持 Types 枚举或类型字符串（string/int/float/bool/array/object）
   *
   * @param mixed $field 配置项
   * @param string $configName 配置名称（用于异常提示）
   * @param int|string $index 配置键（极简格式下作为参数名）
   * @return FieldStructure
   */
  private function toFieldStructure(mixed $field, string $configName, int|string $index
  ): FieldStructure
  {
    if ($field instanceof FieldStructure) return $field;
    if (is_string($field) && is_string($index)) {
      // 极简格式：参数名 => 描述
      return new FieldStructure($index, $field, type: Types::String);
    }
    if (is_array($field)) {
      $fieldName = $field['name'] ?? (is_string($index) ? $index : null);
      if (empty($fieldName)) {
        throw new InvalidArgumentException("$configName($index) 配置错误，缺少name字段");
      }
      return new FieldStructure(
        (string)$fieldName,
        (string)($field['description'] ?? ''),
        (bool)($field['allowNull'] ?? false),
        $field['default'] ?? null,
        self::parseType($field['type'] ?? Types::Mixed)
      );
    }
    throw new InvalidArgumentException(
      "$configName($index) 配置错误，必须是FieldStructure实例、字符串或数组"
    );
  }

  /**
   * 解析类型配置为内置类型枚举
   *
   * @param mixed $type Types枚举或类型字符串（string/int/float/bool/array/object）
   * @return Types
   */
  private static function parseType(mixed $type): Types
  {
    if ($type instanceof Types) return $type;
    if (is_string($type)) {
      return match (strtolower($type)) {
        'string', 'str' => Types::String,
        'int', 'integer' => Types::Int,
        'float', 'double' => Types::Float,
        'bool', 'boolean' => Types::Bool,
        'array' => Types::Array,
        'object' => Types::Object,
        'null' => Types::Null,
        default => Types::Mixed,
      };
    }
    return Types::Mixed;
  }

  /**
   * 校验全局返回值配置
   *
   * @return void
   */
  private function verifyGlobalReturned(): void
  {
    $globalReturned = config('router.api_doc.returned', []);
    if (!is_array($globalReturned)) {
      throw new InvalidArgumentException('router.api_doc.returned 配置错误，必须是数组类型');
    }
    $class = Returned::class;
    foreach ($globalReturned as $item) {
      if (!$item instanceof Returned) {
        throw new InvalidArgumentException("router.api_doc.returned 配置错误，必须是{$class}实例");
      }
    }
  }

  /**
   * 加载路由配置文件（通过 router.route_config_files 配置项指定）
   */
  private function loadConfigRoute(): void
  {
    $loadPaths = $this->config->get('router.route_config_files', []);
    foreach ($loadPaths as $file) {
      require_once $file;
    }
  }

  /**
   * 加载注解路由
   *
   * @return void
   */
  private function loadAnnotationRoute(): void
  {
    $rootPath = getRootPath() . DIRECTORY_SEPARATOR;
    $directory = $rootPath . 'app/Controller';
    // 列出指定路径中的文件和目录
    $controllers = RouterTool::getAllFiles($directory);
    $hash = null;
    foreach ($controllers as $controller) {
      [$fullClass] = RouterTool::getNamespace($controller, $rootPath);
      // 获取路由缓存
      if ($this->cache) {
        // 缓存哈希：框架版本号 + 类文件哈希，框架升级后旧缓存自动失效
        $hash = RouterTool::getCacheHash($controller);
        $cacheGroup = RouterTool::getCache(SERVER_NAME, $fullClass, $hash);
        if ($cacheGroup) {
          $this->recordRouteItem($cacheGroup);
          continue;
        }
      }
      // 没有缓存，则解析路由
      $routeGroup = $this->parseController($controller, $rootPath);
      // 如果没有解析到路由则跳过
      if (empty($routeGroup)) continue;
      // 记录路由
      $this->recordRouteItem($routeGroup);
      // 如果hash不为null则缓存路由
      if (!$hash) continue;
      RouterTool::setCache(SERVER_NAME, $fullClass, $hash, $routeGroup);
    }
  }

  /**
   * 解析控制器
   *
   * @param string $file 控制器文件
   * @param string $rootPath 根目录
   * @return Group|null
   */
  private function parseController(string $file, string $rootPath): ?Group
  {
    [$fullClass] = RouterTool::getNamespace($file, $rootPath);
    if (class_exists($fullClass)) {
      $refClass = new ReflectionClass($fullClass);
      $className = $refClass->getShortName();
    } else {
      return null;
    }
    // 获取路由注解属性
    $classAttributes = $refClass->getAttributes(
      Controller::class, ReflectionAttribute::IS_INSTANCEOF
    );
    // 没有路由控制器注解属性则不解析
    if (empty($classAttributes)) return null;
    /** @var Controller|AutoController $controller 控制器路由注解实例 */
    $controller = $classAttributes[0]->newInstance();
    // 服务名称
    $serverName = $controller->server ?? SERVER_NAME;
    // 判断服务名称是否匹配当前服务
    if (strtolower($serverName) !== strtolower(SERVER_NAME)) return null;
    // 判断是否设置了描述
    if (!isset($controller->title)) {
      $controller->title = DocCommentTool::extractDocTitle($refClass->getDocComment() ?: '');
    }
    /** 是否为自动路由 */
    $isAutoRoute = $controller instanceof AutoController;
    // 如果类路由注解的paths设置为null则默认为类名称
    if ($controller->prefix === null) $controller->prefix = $className;
    // 类完全名称md5值作为路由分组名称
    if (!$controller->id) $controller->id = RouterTool::generateHashId($fullClass);
    /**
     * @var Group $group 路由分组实例
     */
    $group = $controller->create([]);
    // 记录控制器类源码位置（相对项目根目录），供接口文档定位
    $group->setSourceLocation(
      RouterTool::relativeToRoot($refClass->getFileName()),
      $refClass->getStartLine()
    );
    // 类的全部方法
    $methods = $refClass->getMethods();
    if (!empty($methods)) $this->parseMethod($methods, $isAutoRoute, $group);
    return $group;
  }

  /**
   * 解析方法
   *
   * @param ReflectionMethod[] $methods 方法列表
   * @param bool $isAutoRoute 是否自动路由
   * @param Group $group
   * @return void
   */
  private function parseMethod(array $methods, bool $isAutoRoute, Group $group): void
  {
    if (empty($methods)) return;
    $class = $methods[0]->getDeclaringClass()->getName();
    foreach ($methods as $method) {
      // 判断是否需要创建路由
      $isCreate = $method->isPublic()
        && !$method->isConstructor()
        && !$method->isAbstract()
        && !$method->isDestructor();
      // 不需要创建路由则跳过
      if (!$isCreate) continue;
      $methodName = $method->getName();
      // 路由id
      $methodId = RouterTool::generateHashId($class . '::' . $methodName);
      // 获取方法注解
      $methodAttributes = $method->getAttributes(RouteMapping::class);
      // 方法文档注释
      $methodDocComment = $method->getDocComment() ?: '';
      // 构建处理方法
      $handler = $method->isStatic()
        ? $class . '::' . $method->getName()
        : [$class, $method->getName()];
      // 如果没有设置路由注解，且该类为自动路由则创建路由
      if (empty($methodAttributes)) {  // 自动路由
        if (!$isAutoRoute) continue;
        // 创建新的路由项
        $routeItem = new Route($method->getName(), $handler, $group, id: $methodId);
        // 设置标题
        $routeItem->setTitle(DocCommentTool::extractDocTitle($methodDocComment));
        // 设置描述
        $routeItem->setDescription(DocCommentTool::extractDocDescription($methodDocComment));
      } else {
        // 处理设置了路由注解的方法
        /** @var RouteMapping $methodAnnotationRoute 注解路由 */
        $methodAnnotationRoute = $methodAttributes[0]->newInstance();
        // 未声明标题时，从方法文档注释首行提取
        if (empty($methodAnnotationRoute->title)) {
          $methodAnnotationRoute->title = DocCommentTool::extractDocTitle($methodDocComment);
        }
        // 未声明描述时，从方法文档注释正文提取
        if (empty($methodAnnotationRoute->description)) {
          $methodAnnotationRoute->description = DocCommentTool::extractDocDescription(
            $methodDocComment
          );
        }
        // 如果没有设置路由路径则默认为方法名称
        if (empty($methodAnnotationRoute->prefix)) {
          $methodAnnotationRoute->prefix = $methodName;
        }
        // 设置路由id
        if (!$methodAnnotationRoute->id) $methodAnnotationRoute->id = $methodId;
        // 创建路由项
        $routeItem = $methodAnnotationRoute->create($handler, $group);
      }
      // 添加到组的子路由中
      $group->addItem($routeItem);
    }
  }

  /**
   * 解析路由(最后执行)
   *
   * @return void
   */
  private function parseRoute(): void
  {
    // 对路由进行排序
    uasort($this->routes, function (BaseRoute $a, BaseRoute $b) {
      return $b->getSort() <=> $a->getSort();
    });
    foreach ($this->routes as $key => $item) {
      if ($parent = $item->getParentId()) {
        // 处理自定义父级路由依赖
        $routeGroup = $this->getRoute($parent);
        if ($routeGroup instanceof Group) {
          $routeGroup->addItem($item);
          unset($this->routes[$key]);
          continue;
        }
      }
      $this->register($item);
    }
  }

  /**
   * 注册路由
   *
   * @param Group|Route $route
   * @return void
   */
  private function register(Group|Route $route): void
  {
    if ($route instanceof Group) {
      $this->currentGroup = $route;
      foreach ($route->getItem() as $item) {
        $this->register($item);
      }
      $this->currentGroup = null;
    } else {
      foreach ($route->getPaths() as $path) {
        $this->insertRoute($path, $route->getCiteLink());
      }
    }
  }

  /**
   * 将路由插入静态路由表或动态路由树
   *
   * @param string $path 路由路径
   * @param string $routeIndex 路由引用链路
   */
  private function insertRoute(string $path, string $routeIndex): void
  {
    if (RouterTool::isVariable($path)) {
      $urlSegments = explode('/', $path);
      $urlSegments = array_filter($urlSegments, function ($value) {
        return $value !== '';
      });
      $route = $this->getRoute($routeIndex);
      $regex = $this->convertRegex($urlSegments, $route->getPatterns());
      $this->addDynamicRoute($urlSegments, $regex, $routeIndex, $route->getMethod());
    } else {
      $this->addStaticRoute($path, $routeIndex, $this->getRoute($routeIndex)->getMethod());
    }
  }

  /**
   * 将 URL 路径段和参数正则约束转换为完整匹配正则
   *
   * @param string[] $segments URL 路径段数组
   * @param array $patternRule 参数名到正则约束的映射
   * @return string 完整的匹配正则表达式
   */
  private function convertRegex(array $segments, array $patternRule = []): string
  {
    // 大小写不敏感路由（case_sensitive=false）下，静态段以内联 (?i:) 分组包裹：
    // 匹配对请求路径的原始大小写生效，而动态参数约束正则保持自身大小写语义；
    // dispatch 侧因此使用原始路径匹配，避免整体小写导致参数值丢失大小写
    $caseSensitive = $this->config->get('router.case_sensitive', false);
    $regexPattern = '';
    foreach ($segments as $segment) {
      // 判断是否为变量字段
      if (RouterTool::isVariable($segment)) {
        // 判断是否为可选变量
        $isRequire = RouterTool::isOptionalVariable($segment);
        // 提取变量名称
        $segment = RouterTool::extractVariableName($segment);
        // 删除结尾斜杠
        if ($isRequire) $regexPattern = rtrim($regexPattern, '/');
        // 设置规则：使用命名捕获组，dispatch 直接按组名提取参数，
        // 消除"patterns 键序与捕获组顺序对齐"的脆弱假设；
        // 约束正则中的匿名捕获组也不会再混入参数表
        $regexPattern .= $isRequire
          ? '(?:/(?P<' . $segment . '>' . $patternRule[$segment] . '))?'
          : '(?P<' . $segment . '>' . $patternRule[$segment] . ')';
      } else {
        // 否则，将段视为静态文本（大小写不敏感路由时包裹内联忽略大小写分组）
        $static = preg_quote($segment, '/');
        $regexPattern .= $caseSensitive ? $static : "(?i:$static)";
      }
      //结尾添加斜杠
      $regexPattern .= '/';
    }
    // 删除最后一个斜杠
    $regexPattern = rtrim($regexPattern, '/');
    // 添加正则表达式的开始和结束标记
    $regex = '#^/' . $regexPattern . '$#';
    // 编译期校验：重复变量名、非法组名等问题会让正则无法编译，
    // 不在此处拦截将表现为路由静默永不匹配，极难排查
    if (@preg_match($regex, '') === false) {
      throw new InvalidArgumentException(
        '路由正则编译失败（' . implode('/', $segments) . '）: ' . preg_last_error_msg()
      );
    }
    return $regex;
  }

  /**
   * 添加动态路由
   *
   * 按 [正则 => [请求方法 => 路由引用链路]] 存储，同一正则允许不同请求方法共存，
   * 仅当同一路径且同一请求方法重复定义时告警并覆盖。
   *
   * @param string[] $urlSegments URL 路径段数组
   * @param string $regex 匹配正则
   * @param string $routeIndex 路由引用链路
   * @param string[] $methods 路由允许的请求方式列表
   * @return void
   */
  private function addDynamicRoute(
    array  $urlSegments,
    string $regex,
    string $routeIndex,
    array  $methods,
  ): void
  {
    $len = count($urlSegments);
    foreach ($urlSegments as $rule) {
      if (empty($rule)) continue;
      if (RouterTool::isOptionalVariable($rule)) $len--;
    }
    $path = implode('/', $urlSegments);
    // 初始化方法映射表，避免引用传参时未定义维度自动置为 null 触发类型错误
    if (!isset($this->dynamicRoute["segment_$len"][$regex])) {
      $this->dynamicRoute["segment_$len"][$regex] = [];
    }
    $this->registerMethodRoute(
      $this->dynamicRoute["segment_$len"][$regex], $path, $methods, $routeIndex
    );
    $fullLen = count($urlSegments);
    // 适配去掉可选参数的长度
    if ($fullLen !== $len) {
      if (!isset($this->dynamicRoute['segment_' . $fullLen][$regex])) {
        $this->dynamicRoute['segment_' . $fullLen][$regex] = [];
      }
      $this->registerMethodRoute(
        $this->dynamicRoute['segment_' . $fullLen][$regex], $path, $methods, $routeIndex
      );
    }
  }

  /**
   * 将路由按请求方式写入方法映射表（引用方式写入，供静态/动态路由表复用）
   *
   * 包含 '*' 时视为不限制请求方式，仅记录 '*' 键（分发时作为通配回退），
   * 避免 '*' 与具体方法同时注册导致的通配优先级歧义。
   *
   * @param array<string,string> $methodMap 方法映射表（按引用写入）
   * @param string $path 用于告警提示的路径
   * @param string[] $methods 路由允许的请求方式列表
   * @param string $routeIndex 路由引用链路
   * @return void
   */
  private function registerMethodRoute(
    array  &$methodMap,
    string $path,
    array  $methods,
    string $routeIndex
  ): void
  {
    if (in_array('*', $methods)) $methods = ['*'];
    foreach ($methods as $method) {
      if (isset($methodMap[$method])) {
        trigger_error(
          "{$path}路由规则已存在（请求方法：{$method}），重复定义即覆盖路由", E_USER_WARNING
        );
      }
      $methodMap[$method] = $routeIndex;
    }
  }

  /**
   * 注册静态路由到映射表
   *
   * 按 [路径 => [请求方法 => 路由引用链路]] 存储，同一路径允许不同请求方法共存，
   * 仅当同一路径且同一请求方法重复定义时告警并覆盖。
   *
   * @param string $urlPath 完整 URL 路径
   * @param string $routeIndex 路由引用链路
   * @param string[] $methods 路由允许的请求方式列表
   */
  private function addStaticRoute(string $urlPath, string $routeIndex, array $methods): void
  {
    // 初始化方法映射表，避免引用传参时未定义维度自动置为 null 触发类型错误
    if (!isset($this->staticRoute[$urlPath])) $this->staticRoute[$urlPath] = [];
    $this->registerMethodRoute($this->staticRoute[$urlPath], $urlPath, $methods, $routeIndex);
  }

  /**
   * 获取路由文档
   *
   * @return array{count: int,routes: array} 路由数量和路由列表
   * @throws RuntimeException 路由正在初始化
   * @see ApiDocParseTool::generateGroup() 分组结构
   * @see ApiDocParseTool::generateRoute() 路由结构
   */
  public function getApiList(): array
  {
    if (!$this->enableApiDoc) return ['count' => 0, 'routes' => []];
    if (!isset($this->init)) throw new RuntimeException('路由正在初始化，请稍后再试');
    return ApiDocParseTool::parse($this->getRoutes());
  }

  /**
   * 获取API文档的详情，包含请求参数，接口返回值
   *
   * @param string $citeLink 路由线路的引用链接
   * @return array
   * @throws InvalidArgumentException 路由不存在
   * @see Route::getReturned() 响应组件结构详情
   * @see Route::getParams() 参数结构详情
   */
  public function getApiDetail(string $citeLink): array
  {
    $route = $this->getRoute($citeLink);
    return [
      'params' => $route->getParams(),
      'returned' => $route->getReturned()
    ];
  }

  /**
   * 判断是否启用了 API 文档解析功能
   *
   * @return bool
   */
  public function isEnableApiDoc(): bool
  {
    return $this->enableApiDoc;
  }

  /**
   * 匹配路由，返回路由实例
   *
   * 支持同一路径按不同请求方法注册多条路由（如 GET /profile 与 PUT /profile），
   * 匹配时先按路径定位再按请求方法选择路由；路径命中但方法均不匹配时
   * 抛出方法不允许异常（与 404 同样会回退 miss 路由）。
   *
   * @param string $path 路由路径
   * @param string $method 请求方式
   * @param string $domain 请求域名
   * @param array|null $params 请求参数，如果传入则会同动态路由参数合并，并传递给路由处理函数
   * @param callable|null $callback 回调函数，用于处理动态路由参数，例如将动态路由参数添加到Request对象中，该回调触发时间早于调用路由处理函数
   * @return mixed 输出结果
   */
  public function dispatch(
    string    $path,
    string    $method,
    string    $domain,
    ?array    $params = null,
    ?callable $callback = null,
  ): mixed
  {
    $path = $path === '/' ? '/' : rtrim($path, '/');
    // 请求路径以百分号编码到达（如空格 %20、中文），逐段解码后才能与注册路径
    // 及参数约束匹配；逐段处理避免 %2F 解码出新的路径分隔符破坏段结构
    // （注册侧 handlePaths 对静态段同步解码，两侧对齐）
    $decodedPath = $this->decodeRequestPath($path);
    $caseSensitive = $this->config->get('router.case_sensitive', false);
    // 请求方法统一规范化为大写（方法映射表与 miss 路由表均以大写为键）
    $method = strtoupper($method);
    // 候选路径：完整路径优先（点号属于动态参数值，如 /user/john.doe），
    // 命中路由但伪静态后缀校验失败时再尝试剥离后缀的路径（仅按最后一个
    // 不含 / 的点段剥离）。无点号时仅一个候选，保持原语义
    $candidates = [[$decodedPath, '']];
    $dotPos = strrpos($decodedPath, '.');
    if ($dotPos !== false) {
      $ext = substr($decodedPath, $dotPos + 1);
      if ($ext !== '' && !str_contains($ext, '/')) {
        $strippedPath = rtrim(substr($decodedPath, 0, $dotPos), '/');
        if ($strippedPath !== '') $candidates[] = [$strippedPath, $ext];
      }
    }
    $pattern = [];
    /** @var Route|null $route 路由 */
    $route = null;
    // 路径已命中但请求方法均不匹配时为 true，用于区分 404 与方法不允许两种未命中语义
    $pathMatched = false;
    // 路由命中但伪静态后缀校验未通过时暂存的异常，所有候选穷尽后抛出（回退 miss 路由）
    $suffixError = null;
    foreach ($candidates as [$candPath, $ext]) {
      // 静态路由查表用小写键（注册侧静态段已小写）；动态正则匹配用原始大小写路径，
      // 正则静态段以内联 (?i:) 分组实现大小写不敏感，动态参数值保留原始大小写
      $matchPath = $caseSensitive ? $candPath : strtolower($candPath);
      // 判断是否存在静态路由
      if (isset($this->staticRoute[$matchPath])) {
        $routeIndex = $this->selectRouteIndex($this->staticRoute[$matchPath], $method);
        if ($routeIndex !== null) {
          $route = $this->getRoute($routeIndex);
        } else {
          $pathMatched = true;
        }
      } else {
        // 按段数定位动态路由分组
        $routes = $this->dynamicRoute['segment_' . substr_count($candPath, '/')] ?? [];
        // 遍历正则匹配路由（使用原始大小写路径，保留动态参数值大小写）
        foreach (array_keys($routes) as $regex) {
          if (!preg_match($regex, $candPath, $matches)) continue;
          $routeIndex = $this->selectRouteIndex($routes[$regex], $method);
          if ($routeIndex === null) {
            // 路径命中但该正则下无匹配请求方法的路由，
            // 继续尝试后续正则（可能存在另一模式支持该方法的路由）
            $pathMatched = true;
            continue;
          }
          // 拿到路由
          $route = $this->getRoute($routeIndex);
          // 命名捕获组直接给出 变量名 => 值：与路由 patterns 键求交集，
          // 数字键（PCRE 自动编号）与约束正则内部自定义的命名组均被过滤，
          // 仅保留路径声明的变量；未匹配的可选变量不出现在结果中，
          // 由参数注入层用方法默认值兜底。不再依赖 patterns 键序与捕获组
          // 顺序对齐（旧实现遇约束正则含捕获组时 array_combine 会崩溃）
          $pattern = array_intersect_key($matches, $route->getPatterns());
          break;
        }
      }
      // 未命中任何路由则尝试下一候选
      if ($route === null) continue;
      // 伪静态后缀校验不通过时不抛异常，回退下一候选：
      // 完整路径候选可能把后缀误当作参数值的一部分（如 /article/5.html 与 {id}）
      if (!in_array('*', $route->getSuffix()) && !in_array($ext, $route->getSuffix())) {
        $suffixError = new RouteNotFoundException("request suffix '$ext' is not allowed");
        $route = null;
        $pattern = [];
        continue;
      }
      break;
    }
    try {
      if (is_null($route)) {
        if ($suffixError !== null) throw $suffixError;
        throw new RouteNotFoundException(
          $pathMatched
            ? "request method '$method' is not allowed"
            : 'routing resource not found'
        );
      }
      // 判断请求方法（路由已按方法维度选出，此处为防御性校验；
      // OPTIONS 预检请求跳过方法校验：预检不对应真实处理器，
      // 需放行进入中间件管道，由跨域中间件短路响应，避免预检被 404 拦截）
      if ($method !== 'OPTIONS') {
        $this->checkOption(
          $route->getMethod(), $method, "request method '$method' is not allowed"
        );
      }
      // 判断域名
      $this->checkOption($route->getDomain(), $domain, "request domain '$domain' is not allowed");
      // 伪静态后缀已在候选循环中校验（不通过时回退下一候选），无需重复校验
      // 动态路由参数先交由回调处理（如 HTTP 场景并入 Request 的 GET 参数）
      if (!empty($pattern) && $callback) $callback($pattern);
      // 修复#8: $params 必须先初始化再合并，否则未显式传参时（如 HTTP 请求）动态路由
      // 参数无法并入注入参数，方法参数就不能按名隐式获取路由参数（/user/1024 → $id = 1024）。
      // 合并语义保持不变：路由匹配参数优先于手动传入的同名参数；GET/POST 请求参数仍需注解显式注入
      $params = array_merge($params ?? [], $pattern);
      // 绑定到容器
      bind(Route::class, $route);
      return $this->middleware->process(function () use ($route, $params) {
        return invoke($route->getHandler(), $params);
      }, $route->getMiddlewares());
    } catch (RouteNotFoundException $e) {
      // 匹配miss路由
      if (isset($this->missRoutes[$method])) {
        $miss = $this->missRoutes[$method];
      } elseif (isset($this->missRoutes['*'])) {
        $miss = $this->missRoutes['*'];
      }
      // 如果匹配到miss路由执行处理方法
      if (isset($miss)) return $miss->handler();
      // 未匹配则抛出异常
      throw $e;
    }
  }

  /**
   * 对请求路径逐段进行 URL 解码
   *
   * 请求路径以百分号编码形式到达（如空格 %20、中文），解码后才能与注册的
   * 路由路径及参数约束匹配（注册侧 handlePaths 对静态段同步解码）。
   * 按 / 分段后逐段解码；解码后包含 / 的段（如 %2F）保留编码原样，
   * 防止编码斜杠被还原为真实路径分隔符导致路径结构被重写
   * （如 /user/a%2Fb 被改写为 /user/a/b 而匹配到其他路由）。
   *
   * @param string $path 原始请求路径
   * @return string 解码后的路径
   */
  private function decodeRequestPath(string $path): string
  {
    // 无编码字符时直接返回，免去拆分开销
    if (!str_contains($path, '%')) return $path;
    $segments = explode('/', $path);
    foreach ($segments as &$segment) {
      if ($segment === '') continue;
      $decoded = rawurldecode($segment);
      // 解码引入新分隔符的段保持编码原样（注册侧 handlePaths 同规则，两侧对齐）
      if (!str_contains($decoded, '/')) $segment = $decoded;
    }
    unset($segment);
    return implode('/', $segments);
  }

  /**
   * 从方法映射表中选择匹配请求方法的路由引用链路
   *
   * 选择优先级：精确方法匹配 > '*' 通配 > （仅 OPTIONS）该路径下任意路由。
   * OPTIONS 预检请求不对应真实处理器，回退到任意路由以放行进入中间件管道，
   * 由跨域中间件短路响应。
   *
   * @param array<string,string> $methodMap [请求方法 => 路由引用链路] 映射表
   * @param string $method 规范化后的请求方法（大写）
   * @return string|null 路由引用链路，无匹配时返回 null
   */
  private function selectRouteIndex(array $methodMap, string $method): ?string
  {
    if (isset($methodMap[$method])) return $methodMap[$method];
    if (isset($methodMap['*'])) return $methodMap['*'];
    if ($method === 'OPTIONS') {
      // reset() 在空数组上返回 false，需显式判空
      return $methodMap === [] ? null : reset($methodMap);
    }
    return null;
  }

  /**
   * 校验请求选项（方法/域名/后缀）是否在允许列表中
   *
   * 允许列表中包含 '*' 时视为不限制。
   *
   * @param array $option 允许的值列表
   * @param string $value 实际请求值
   * @param string $message 不匹配时的异常消息
   * @throws RouteNotFoundException 不匹配时抛出
   */
  private function checkOption(
    array  $option,
    string $value,
    string $message
  ): void
  {
    if (
      !in_array('*', $option)
      && !in_array($value, $option)
    ) {
      throw new RouteNotFoundException($message);
    }
  }
}
