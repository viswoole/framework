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

declare(strict_types=1);

namespace Viswoole\Core;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Override;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionType;
use TypeError;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Contract\PreInjectInterface;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Core\Exception\ClassNotFoundException;
use Viswoole\Core\Exception\FuncNotFoundException;
use Viswoole\Core\Exception\MethodNotFoundException;
use Viswoole\Core\Exception\NotFoundException;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\BaseValidateRule;

/**
 * 依赖注入容器
 *
 * 提供服务绑定、依赖解析、单例管理和反射调用能力。
 * 支持 ArrayAccess/Countable/IteratorAggregate 接口，可像数组一样访问容器绑定。
 * 单例存储区分协程上下文与进程全局，确保协程间单例隔离。
 */
abstract class Container implements ArrayAccess, IteratorAggregate, Countable
{
  /**
   * @var string 协程上下文中单例键名的前缀，避免与用户数据冲突
   */
  protected string $CONTEXT_PREFIX = '__container_singleton_';
  /**
   * @var array<string,string|Closure> 接口标识映射
   */
  protected array $bindings = [];
  /**
   * @var object[] 已解析的单例实例池（非协程环境使用）
   */
  protected array $instances = [];
  /**
   * @var array<string,array<string,Closure>> 类解析后触发的回调钩子，键为类名或 '*'（通配）
   */
  protected array $invokeCallback = [];

  /**
   * 检测给定的回调是否可通过 invoke 系列方法调用
   *
   * 支持闭包、函数名、类名、[类名, 方法名] 数组等形式
   *
   * @param mixed $handle 待检测的回调结构
   * @param bool $throw 检测不通过时是否抛出异常
   * @return bool 可调用返回 true
   * @throws InvalidArgumentException 当 $throw 为 true 且不可调用时抛出
   */
  public static function isCallable(mixed $handle, bool $throw = false): bool
  {
    if (is_callable($handle)) return true;
    if (is_string($handle)) {
      if (class_exists($handle)) return true;
      if (function_exists($handle)) return true;
    } elseif (is_array($handle)) {
      if (count($handle) === 2) {
        [$class, $method] = array_values($handle);
        if (class_exists($class) && method_exists($class, $method)) return true;
      }
    }
    if ($throw) throw new InvalidArgumentException('$handle 无法调用，请检查。');
    return false;
  }

  /**
   * 注册类解析后的回调钩子，在 invokeClass 创建实例后触发
   *
   * Example:
   * ```
   * $container->addHook(UserService::class, function($object, $container){
   *   // 对 UserService 实例进行额外操作
   * })
   * ```
   *
   * @param string $abstract 类名，传入 '*' 监听所有类的解析
   * @param Closure $callback 回调函数，参数为 (object $instance, Container $container)
   * @return string 钩子唯一哈希标识，用于 removeHook 移除
   */
  public function addHook(string $abstract, Closure $callback): string
  {
    $key = $abstract;
    $id = md5($abstract . spl_object_id($callback));
    $this->invokeCallback[$key][$id] = $callback;
    return $id;
  }

  /**
   * 移除类解析钩子
   *
   * @param string $abstract 类名或 '*'
   * @param string|null $id 钩子标识，为 null 时移除该类的所有钩子
   */
  public function removeHook(string $abstract, ?string $id = null): void
  {
    if (isset($this->invokeCallback[$abstract])) {
      if (is_null($id)) {
        unset($this->invokeCallback[$abstract]);
      } else {
        unset($this->invokeCallback[$abstract][$id]);
      }
    }
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function offsetExists(mixed $offset): bool
  {
    return $this->has($offset);
  }

  /**
   * 判断容器中是否绑定或实例化了指定标识
   *
   * @param string $id 绑定标识或类名
   * @return bool 存在绑定或实例返回 true
   */
  public function has(string $id): bool
  {
    return isset($this->bindings[$id]) || isset($this->instances[$id]);
  }

  /**
   * 判断指定类是否已有单例实例（含协程上下文）
   *
   * @param string $class 类名
   * @return bool 存在实例返回 true
   */
  public function hasInstance(string $class): bool
  {
    $result = $this->getSingleton($class);
    return is_object($result);
  }

  /**
   * 获取单例实例，优先从进程级实例池取，协程环境下从协程上下文取
   *
   * @param string $class 类名
   * @return object|null 存在则返回实例，否则返回 null
   */
  protected function getSingleton(string $class): ?object
  {
    if (isset($this->instances[$class])) return $this->instances[$class];
    if (Coroutine::isCoroutine()) return Context::get($this->CONTEXT_PREFIX . $class);
    return null;
  }

  /**
   * 从容器中获取绑定标识对应的实例
   *
   * @param string $id 绑定标识或类名
   * @return object 解析得到的实例
   * @throws NotFoundException 标识未绑定时抛出
   */
  public function get(string $id): object
  {
    if ($this->has($id)) return $this->make($id);
    throw new NotFoundException("Container $id not found");
  }

  /**
   * 创建已绑定服务的单例实例，若已存在则直接返回
   *
   * 当类定义了 ALLOW_NEW_INSTANCE = true 常量时，每次调用都创建新实例而不缓存单例
   *
   * @param string $abstract 绑定标识或类名
   * @param array $params 构造参数，覆盖依赖注入
   * @return object 解析得到的单例实例
   * @throws NotFoundException 类或闭包不可达时抛出
   */
  public function make(string $abstract, array $params = []): object
  {
    // 获取绑定的实现类
    $concrete = $this->getBind($abstract);
    // 如果得到的不是闭包则赋值给$abstract
    if (is_string($concrete)) $abstract = $concrete;
    // 获取单例
    $instance = $this->getSingleton($abstract);
    // 存在单实例则返回
    if ($instance) return $instance;
    // 反射实例
    $instance = is_string($concrete)
      ? $this->invokeClass($concrete, $params)
      : $this->invokeFunction($concrete, $params);
    // 修复#13: 常量名NOT_ALLOW_NEW_INSTANCE语义相反，改为ALLOW_NEW_INSTANCE
    $class = get_class($instance) . '::ALLOW_NEW_INSTANCE';
    // 判断类是否设置了ALLOW_NEW_INSTANCE常量
    /** @noinspection PhpUnhandledExceptionInspection */
    $allowNewInstance = defined($class) ? constant($class) : false;
    // 如果类没有设置ALLOW_NEW_INSTANCE属性，或设置为false则缓存单实例
    if ($allowNewInstance === false) $this->setSingleInstance($abstract, $instance);
    return $instance;
  }

  /**
   * 根据绑定标识获取映射的实现类名或闭包
   *
   * @param string $abstract 绑定标识
   * @return string|Closure 映射的类名或闭包，未绑定时返回原值
   */
  protected function getBind(string $abstract): string|Closure
  {
    return $this->bindings[$abstract] ?? $abstract;
  }

  /**
   * 通过反射创建类实例，自动解析构造函数依赖
   *
   * 优先使用类的 factory() 静态方法（若存在且为 public static），否则走 __construct
   *
   * @param string $class 要实例化的类名
   * @param array $params 手动传入的构造参数，按名称或位置匹配
   * @return object 创建的类实例
   * @throws ClassNotFoundException 类不存在时抛出
   * @throws ValidateException 参数类型校验失败时抛出
   */
  public function invokeClass(string $class, array $params = []): object
  {
    try {
      $reflector = new ReflectionClass($class);
    } catch (ReflectionException $e) {
      throw new ClassNotFoundException($e->getMessage(), previous: $e);
    }
    $construct = '__construct()';
    try {
      if ($reflector->hasMethod('factory')) {
        $method = $reflector->getMethod('factory');
        if ($method->isPublic() && $method->isStatic()) {
          $construct = 'factory()';
          $args = $this->injectParams($method, $params);
          /** @noinspection PhpUnhandledExceptionInspection */
          return $method->invokeArgs(null, $args);
        }
      }
      $constructor = $reflector->getConstructor();
      $args = $constructor ? $this->injectParams($constructor, $params) : [];
    } catch (ValidateException $e) {
      $this->handleValidateError(
        $reflector->getName() . "::$construct: " . $e->getMessage(),
        $e
      );
    }
    try {
      $instance = $reflector->newInstanceArgs($args);
      $this->invokeAfter($class, $instance);
      return $instance;
    } catch (ReflectionException $e) {
      throw new ClassNotFoundException($e->getMessage(), previous: $e);
    }
  }

  /**
   * 解析反射方法的参数列表，依次处理前置注入、依赖注入和类型校验
   *
   * 支持命名参数、位置参数、可变参数、PreInjectInterface 属性注入和 ValidateRule 属性校验
   *
   * @param ReflectionFunctionAbstract $reflect 反射方法
   * @param array $params 手动传入的参数，按名称或位置覆盖
   * @return array<int,mixed> 按位置索引的参数值数组
   * @throws NotFoundException 依赖不可达时抛出
   */
  protected function injectParams(ReflectionFunctionAbstract $reflect, array $params = []): array
  {
    $shapes = $reflect->getParameters();
    // 如果没有参数 则返回空待注入参数数组
    if (empty($shapes)) return [];

    /** @var array<int,mixed> $args 最终要注入的参数 */
    $args = [];
    foreach ($shapes as $index => $shape) {
      try {
        // 参数类型
        $paramType = $shape->getType();
        // 是否允许为null
        $allowsNull = $shape->allowsNull();
        // 参数名称
        $name = $shape->getName();
        // 先判断是否存在命名，不存在则使用位置
        $key = array_key_exists($name, $params) ? $name : $index;
        // 参数默认值
        $default = $shape->isDefaultValueAvailable() ? $shape->getDefaultValue() : null;
        // 获得值
        $value = Arr::arrayPopValue($params, $key, $default);
        // 前置注入
        $preInject = $shape->getAttributes(
          PreInjectInterface::class,
          ReflectionAttribute::IS_INSTANCEOF
        );
        // 扩展验证规则
        $validateAttributes = $shape->getAttributes(
          BaseValidateRule::class,
          ReflectionAttribute::IS_INSTANCEOF
        );
        // 如果是可变参数则返回参数数组
        if ($shape->isVariadic()) {
          // 将剩下的参数列表丢给前置注入
          foreach ($preInject as $inject) {
            // 前置注入可变数量参数时 默认允许为空数组
            $params = $inject->newInstance()->inject($name, $params, true);
            // 可变数量参数在注入时必须是数组
            if (!is_array($params)) $params = [];
          }
          foreach ($params as &$item) {
            $item = $this->validateParam(
              $name,
              $paramType,
              $item,
              $allowsNull,
              $validateAttributes
            );
          }
          return array_merge($args, $params);
        }
        // 执行所有前置注入
        foreach ($preInject as $inject) {
          $value = $inject->newInstance()->inject($name, $value, $allowsNull);
        }
        // 验证参数类型
        $value = $this->validateParam(
          $name,
          $paramType,
          $value,
          $allowsNull,
          $validateAttributes
        );
        $args[$index] = $value;
      } catch (ValidateException $e) {
        $this->handleParamsError($index, $name, $e);
      }
    }
    return $args;
  }

  /**
   * 对单个参数执行类型校验和扩展规则校验
   *
   * 内置类型通过 Validate::check 校验，扩展规则通过 Validate::checkRules 校验
   *
   * @param string $name 参数名称
   * @param ReflectionType|null $paramType 参数声明的类型
   * @param mixed $value 待校验的值
   * @param bool $allowsNull 参数是否允许 null
   * @param ReflectionAttribute[] $validateAttributes 扩展验证属性列表
   * @return mixed 校验通过的值
   * @throws ValidateException 类型不匹配时抛出
   */
  protected function validateParam(
    string              $name,
    ReflectionType|null $paramType,
    mixed               $value,
    bool                $allowsNull,
    array               $validateAttributes
  ): mixed
  {
    if (!is_null($paramType)) {
      // 如果$value等于null 且设置的是内置类型 则判断是否允许为null，如果允许则返回null，否则抛出异常
      if (is_null($value) && $paramType->isBuiltin()) {
        if ($allowsNull) return null;
        throw new ValidateException("$$name must be of type $paramType, null given");
      }
      // 进行类型验证
      $value = Validate::check($value, $paramType);
    }
    // 验证扩展规则
    return Validate::checkRules($validateAttributes, $value, $name);
  }

  /**
   * 处理参数注入时的类型校验错误，debug 模式下附带参数位置信息
   *
   * @param int $index 参数位置索引（从 0 开始）
   * @param string $name 参数名称
   * @param ValidateException $e 原始校验异常
   * @throws ValidateException 始终抛出，debug 模式下消息包含参数位置
   */
  protected function handleParamsError(int $index, string $name, ValidateException $e): void
  {
    $index++;
    if (!$this->isDebug()) {
      throw $e;
    } else {
      throw new ValidateException(
        "Argument #$index ($$name) " . $e->getMessage(),
        previous: $e
      );
    }
  }

  /**
   * 处理反射过程中的类型校验错误，debug 模式下附带方法签名上下文
   *
   * @param string $message 附加上下文的错误消息
   * @param ValidateException $e 原始校验异常
   * @throws ValidateException 始终抛出，debug 模式下使用 $message 作为消息
   */
  protected function handleValidateError(string $message, ValidateException $e): void
  {
    if ($this->isDebug()) {
      throw new ValidateException($message, previous: $e);
    } else {
      throw $e;
    }
  }

  /**
   * 在类实例创建后触发已注册的解析钩子，先执行通配 '*' 钩子再执行类名钩子
   *
   * @param string $class 类名
   * @param object $object 刚创建的实例
   */
  protected function invokeAfter(string $class, object $object): void
  {
    if (isset($this->invokeCallback['*'])) {
      foreach ($this->invokeCallback['*'] as $callback) {
        $callback($object, $this);
      }
    }
    if (isset($this->invokeCallback[$class])) {
      foreach ($this->invokeCallback[$class] as $callback) {
        $callback($object, $this);
      }
    }
  }

  /**
   * 通过反射调用函数或闭包，自动解析参数依赖
   *
   * @param string|Closure $concrete 函数名或闭包
   * @param array<string|int,mixed> $params 手动传入的参数
   * @return mixed 函数返回值
   * @throws FuncNotFoundException 函数不存在时抛出
   */
  public function invokeFunction(string|Closure $concrete, array $params = []): mixed
  {
    try {
      $reflect = new ReflectionFunction($concrete);
    } catch (ReflectionException $e) {
      throw new FuncNotFoundException($e->getMessage(), previous: $e);
    }
    try {
      $args = $this->injectParams($reflect, $params);
    } catch (ValidateException $e) {
      $this->handleValidateError($reflect->getName() . '(): ' . $e->getMessage(), $e);
    }
    return $reflect->invoke(...$args);
  }

  /**
   * 统一调用入口，根据 $callable 类型分发到 invokeFunction/invokeMethod/invokeClass
   *
   * @param callable|string|array $callable 闭包、函数名、[类/对象, 方法名]、'类名::方法名'、类名
   * @param array $params 手动传入的参数
   * @return mixed 调用返回值
   * @throws NotFoundException 依赖不可达时抛出
   * @throws ValidateException 参数类型校验失败时抛出
   * @throws TypeError $callable 不可调用时抛出
   */
  public function invoke(callable|string|array $callable, array $params = []): mixed
  {
    if ($callable instanceof Closure) {
      return $this->invokeFunction($callable, $params);
    } elseif (is_array($callable)) {
      return $this->invokeMethod($callable, $params);
    } elseif (is_string($callable)) {
      if (str_contains($callable, '::')) {
        return $this->invokeMethod($callable, $params);
      } elseif (class_exists($callable)) {
        return $this->invokeClass($callable, $params);
      } elseif (function_exists($callable)) {
        return $this->invokeFunction($callable, $params);
      }
    }
    throw new TypeError(
      self::class . 'invoke()方法，参数#1($callable)错误，必须给定可调用的(callable)结构。'
    );
  }

  /**
   * 通过反射调用类方法，自动解析参数依赖
   *
   * 支持对象方法、静态方法、[类名, 方法名]（自动实例化类）和 '类名::方法名' 格式
   *
   * @param array|callable $method 方法描述，如 [object, 'method']、[ClassName::class, 'method']、'ClassName::method'
   * @param array $params 手动传入的参数
   * @return mixed 方法返回值
   * @throws MethodNotFoundException 方法不存在时抛出
   */
  public function invokeMethod(array|callable $method, array $params = []): mixed
  {
    // 修复: 闭包调用时需要传递 $params，否则参数被丢弃
    if ($method instanceof Closure) return $this->invokeFunction($method, $params);
    try {
      $instance = null;
      if (is_array($method)) {
        $class = $method[0];
        if (is_object($method[0])) {
          // 调用对象方法
          $class = get_class($method[0]);
          $reflect = new ReflectionMethod($method[0], $method[1]);
        } elseif (is_callable($method)) {
          // 类静态方法调用
          $reflect = new ReflectionMethod($method[0], $method[1]);
        } else {
          // 兼容静态方式 调用类动态方法
          $instance = $this->invokeClass($method[0]);
          $reflect = new ReflectionMethod($instance, $method[1]);
        }
        $namespaceName = $class . '::' . $method[1];
      } else {
        $reflect = ReflectionMethod::createFromMethodName($method);
        $namespaceName = $method;
      }
      try {
        // 绑定参数
        $args = $this->injectParams($reflect, $params);
      } catch (ValidateException $e) {
        $this->handleValidateError($namespaceName . '(): ' . $e->getMessage(), $e);
      }
      // 调用方法并传入参数
      return $reflect->invokeArgs($instance, $args);
    } catch (ReflectionException $e) {
      throw new MethodNotFoundException($e->getMessage(), previous: $e);
    }
  }

  /**
   * 存储单例实例，协程环境下写入父协程上下文以实现协程间隔离，非协程环境写入进程级实例池
   *
   * @param string $class 类名或绑定标识
   * @param object $instance 单例实例
   */
  protected function setSingleInstance(string $class, object $instance): void
  {
    if (Coroutine::isCoroutine()) {
      Context::set(
        $this->CONTEXT_PREFIX . $class,
        $instance,
        Coroutine::getTopId()
      );
    } else {
      $this->instances[$class] = $instance;
    }
  }

  /**
   * 获取所有绑定映射关系
   *
   * @return string[] 绑定标识到实现类名/闭包的映射数组
   */
  public function getBindings(): array
  {
    return $this->bindings;
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  #[Override]
  public function offsetGet(mixed $offset): mixed
  {
    return $this->get($offset);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function offsetSet(mixed $offset, mixed $value): void
  {
    $this->bind($offset, $value);
  }

  /**
   * 绑定接口标识到实现类、闭包或实例
   *
   * Example:
   * ```
   * $app->bind(ExampleInterface::class, ExampleClass::class);
   * ```
   *
   * @param string $abstract 接口名、类名或自定义标识
   * @param string|object $concrete 实现类名、闭包或已有实例
   * @throws TypeError $concrete 为字符串但不是有效类名时抛出
   */
  public function bind(string $abstract, string|object $concrete): void
  {
    if (is_string($concrete) && !class_exists($concrete)) {
      throw new TypeError(
        self::class . 'bind()方法，参数#2($concrete)错误，必须给定类名|闭包，给定无效类名。'
      );
    }
    if (is_string($concrete) || $concrete instanceof Closure) {
      $this->bindings[$abstract] = $concrete;
    } else {
      $this->setSingleInstance($abstract, $concrete);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function offsetUnset(mixed $offset): void
  {
    unset($this->bindings[$offset]);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function getIterator(): ArrayIterator
  {
    return new ArrayIterator($this->bindings);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function count(): int
  {
    return count($this->bindings);
  }

  /**
   * @param string $name 属性名
   */
  public function __unset(string $name)
  {
    $this->remove($name);
  }

  /**
   * 从容器中移除指定标识的单例实例，同时清理协程上下文中的缓存
   *
   * @param string $abstract 绑定标识或类名
   */
  public function remove(string $abstract): void
  {
    $class = $this->getBind($abstract);
    $key = is_string($class) ? $class : $abstract;
    unset($this->instances[$key]);
    // 修复#6: remove()未清理协程上下文单例，补充协程上下文清理
    if (Coroutine::isCoroutine()) {
      Context::remove($this->CONTEXT_PREFIX . $key, Coroutine::getTopId());
    }
  }

  /**
   * 通过属性访问容器中的服务实例（代理到 make）
   *
   * @param string $name 绑定标识
   * @return mixed 解析得到的实例
   * @throws NotFoundException 标识未绑定时抛出
   */
  public function __get(string $name)
  {
    return $this->make($name);
  }

  /**
   * 通过属性绑定服务到容器（代理到 bind）
   *
   * @param string $name 绑定标识
   * @param mixed $value 实现类名、闭包或实例
   */
  public function __set(string $name, mixed $value): void
  {
    $this->bind($name, $value);
  }
}
