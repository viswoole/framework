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
 * 容器基本功能类
 */
abstract class Container implements ArrayAccess, IteratorAggregate, Countable
{
  protected string $CONTEXT_PREFIX = '__container_singleton_';
  /**
   * @var array<string,string|Closure> 接口标识映射
   */
  protected array $bindings = [];
  /**
   * @var object[] 容器中记录的单例
   */
  protected array $instances = [];
  /**
   * @var array 解析类时的需要触发的回调
   */
  protected array $invokeCallback = [];

  /**
   * 检测是否可调用
   *
   * @param mixed $handle 待检测的回调
   * @param bool $throw 不合格是否抛出异常
   * @return bool 如果通过self::invoke方法可以调用返回true，否则返回false
   * @throws InvalidArgumentException 如果不合格，且$throw为true，则抛出异常
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
   * 添加一个钩子，在解析类时触发
   *
   * Example:
   * ```
   * $container->addHook(UserService::class,function($object,$container){
   *   // 这里可以对UserService对象进行操作
   * })
   * ```
   *
   * @access public
   * @param string $abstract 类名,可传入*代表所有
   * @param Closure $callback 事件回调
   * @return string 返回钩子唯一哈希标识
   */
  public function addHook(string $abstract, Closure $callback): string
  {
    $key = $abstract;
    $id = md5($abstract . spl_object_id($callback));
    $this->invokeCallback[$key][$id] = $callback;
    return $id;
  }

  /**
   * 删除解析钩子
   *
   * @access public
   * @param string $abstract 类标识或类名
   * @param string|null $id
   * @return void
   */
  public function removeHook(string $abstract, string $id = null): void
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
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return $this->has($offset);
  }

  /**
   * 判断容器中是否绑定某个接口
   *
   * @param string $id
   * @return bool
   */
  public function has(string $id): bool
  {
    return isset($this->bindings[$id]) || isset($this->instances[$id]);
  }

  /**
   * 判断是否存在某个类的实例
   *
   * @access public
   * @param string $class
   * @return bool
   */
  public function hasInstance(string $class): bool
  {
    $result = $this->getSingleton($class);
    return is_object($result);
  }

  /**
   * 获取单实例
   *
   * @param string $class
   * @return object|null
   */
  protected function getSingleton(string $class): ?object
  {
    if (isset($this->instances[$class])) return $this->instances[$class];
    if (Coroutine::isCoroutine()) return Context::get($this->CONTEXT_PREFIX . $class);
    return null;
  }

  /**
   * 从容器绑定中获取实例
   *
   * @param string $id
   * @return object
   * @throws NotFoundException
   */
  public function get(string $id): object
  {
    if ($this->has($id)) return $this->make($id);
    throw new NotFoundException("Container $id not found");
  }

  /**
   * 创建一个已绑定的服务，或反射创建类实例，将存储为单例
   *
   * @param string $abstract
   * @param array $params
   * @return object
   * @throws NotFoundException
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
    // 获取常量
    $class = get_class($instance) . '::NOT_ALLOW_NEW_INSTANCE';
    // 判断类是否设置了allowNewInstance常量
    /** @noinspection PhpUnhandledExceptionInspection */
    $allowNewInstance = defined($class) ? constant($class) : false;
    // 如果类没有设置allowNewInstance属性，或设置为false则缓存单实例
    if (!$allowNewInstance) $this->setSingleInstance($abstract, $instance);
    return $instance;
  }

  /**
   * 通过标识获取到真实映射的类名
   *
   * @param string $abstract 标识
   * @return string|Closure 获取类
   */
  protected function getBind(string $abstract): string|Closure
  {
    return $this->bindings[$abstract] ?? $abstract;
  }

  /**
   * 调用反射创建类实例，支持依赖注入。
   *
   * @param string $class
   * @param array $params
   * @return object
   * @throws NotFoundException
   * @noinspection PhpDocMissingThrowsInspection
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
        $reflector->getName() . "::$construct: " . $e->getMessage(), $e
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
   * 注入参数
   *
   * @param ReflectionFunctionAbstract $reflect 反射方法
   * @param array $params 传递的参数
   * @return array<int,mixed>
   * @throws NotFoundException
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
          PreInjectInterface::class, ReflectionAttribute::IS_INSTANCEOF
        );
        // 扩展验证规则
        $validateAttributes = $shape->getAttributes(
          BaseValidateRule::class, ReflectionAttribute::IS_INSTANCEOF
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
              $name, $paramType, $item, $allowsNull, $validateAttributes
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
          $name, $paramType, $value, $allowsNull, $validateAttributes
        );
        $args[$index] = $value;
      } catch (ValidateException $e) {
        $this->handleParamsError($index, $name, $e);
      }
    }
    return $args;
  }

  /**
   * 验证参数
   *
   * @param string $name 参数名称
   * @param ReflectionType|null $paramType 参数类型
   * @param mixed $value 值
   * @param bool $allowsNull 是否允许为空
   * @param ReflectionAttribute[] $validateAttributes 扩展属性
   * @return mixed
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
   * 处理注入参数时类型错误
   *
   * @param int $index
   * @param string $name
   * @param ValidateException|string $e
   * @return void
   */
  protected function handleParamsError(int $index, string $name, ValidateException|string $e): void
  {
    $index++;
    if (is_string($e)) {
      throw new ValidateException(
        $this->isDebug() ? "Argument #$index ($$name) " . $e : $e
      );
    } else {
      if (!$this->isDebug()) {
        throw $e;
      } else {
        throw new ValidateException(
          "Argument #$index ($$name) " . $e->getMessage(), previous: $e
        );
      }
    }

  }

  /**
   * 处理反射验证类型错误
   *
   * @param string $message
   * @param ValidateException $e
   * @return void
   * @throws ValidateException
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
   * 执行invokeClass回调
   *
   * @access protected
   * @param string $class 对象类名
   * @param object $object 容器对象实例
   * @return void
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
   * 反射调用函数
   *
   * @param string|Closure $concrete
   * @param array<string|int,mixed> $params
   * @return mixed
   * @throws NotFoundException
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
   * 反射调用
   *
   * @access public
   * @param callable|string|array $callable
   * @param array $params
   * @return mixed
   * @throws NotFoundException|ValidateException
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
   * 调用反射执行方法，支持依赖注入。
   *
   * @access public
   * @param array|callable $method 方法[object|class,method]|class::method
   * @param array $params 参数
   * @return mixed
   */
  public function invokeMethod(array|callable $method, array $params = []): mixed
  {
    if ($method instanceof Closure) return $this->invokeFunction($method);
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
        $reflect = new ReflectionMethod($method);
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
   * 设置单实例（存储在父协程中），协程结束自动销毁
   *
   * @param string $class
   * @param object $instance
   * @return void
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
   * 获取绑定关系
   *
   * @access public
   * @return string[]
   */
  public function getBindings(): array
  {
    return $this->bindings;
  }

  /**
   * @inheritDoc
   * @throws NotFoundException
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    return $this->get($offset);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    $this->bind($offset, $value);
  }

  /**
   * 绑定接口
   *
   * Example:
   * ```
   *  $app->bind(ExampleInterface::class, ExampleClass::class);
   * ```
   * @param string $abstract 接口|类名
   * @param string|object $concrete 实现类|实例|闭包
   * @return void
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
  #[Override] public function offsetUnset(mixed $offset): void
  {
    unset($this->bindings[$offset]);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getIterator(): ArrayIterator
  {
    return new ArrayIterator($this->bindings);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function count(): int
  {
    return count($this->bindings);
  }

  /**
   * @param $name
   * @return void
   */
  public function __unset($name)
  {
    $this->remove($name);
  }

  /**
   * 删除容器中的服务实例
   *
   * @access public
   * @param string $abstract
   * @return void
   */
  public function remove(string $abstract): void
  {
    $class = $this->getBind($abstract);
    unset($this->instances[is_string($class) ? $class : $abstract]);
  }

  /**
   * 获取容器中的服务实例
   *
   * @param string $name
   * @return mixed
   * @throws NotFoundException
   */
  public function __get(string $name)
  {
    return $this->make($name);
  }

  /**
   * 绑定服务到容器
   *
   * @param string $name
   * @param $value
   * @return void
   */
  public function __set(string $name, $value): void
  {
    $this->bind($name, $value);
  }
}
