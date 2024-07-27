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
use IteratorAggregate;
use Override;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionType;
use TypeError;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Core\Exception\ClassNotFoundException;
use Viswoole\Core\Exception\FuncNotFoundException;
use Viswoole\Core\Exception\MethodNotFoundException;
use Viswoole\Core\Exception\NotFoundException;
use Viswoole\Core\Exception\ValidateException;

/**
 * 容器基本功能类
 */
abstract class Container implements ArrayAccess, IteratorAggregate, Countable
{
  protected string $CONTEXT_PREFIX = '__container_singleton_';
  /**
   * @var array<string,string> 接口标识映射
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
   * 添加一个钩子，在解析类时触发
   *
   * Example:
   * ```
   * $container->addHook(UserService::class,function($object,$container){
   *   // 这里可以对UserService对象进行操作
   * })
   * ```
   * @access public
   * @param string $abstract 类名,可传入*代表所有
   * @param Closure $callback 事件回调
   * @return void
   */
  public function addHook(string $abstract, Closure $callback): void
  {
    $key = $abstract;
    $this->invokeCallback[$key][] = $callback;
  }

  /**
   * 删除解析钩子
   *
   * @access public
   * @param string $abstract 类标识或类名
   * @param Closure|null $callback 回调函数
   * @return void
   */
  public function removeHook(string $abstract, Closure $callback = null): void
  {
    if (isset($this->invokeCallback[$abstract])) {
      if (is_null($callback)) {
        unset($this->invokeCallback[$abstract]);
      } else {
        $index = array_search($callback, $this->invokeCallback[$abstract]);
        if ($index !== false) unset($this->invokeCallback[$abstract][$index]);
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
    // 缓存单实例
    $this->setSingleInstance($abstract, $instance);
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
   */
  public function invokeClass(string $class, array $params = []): object
  {
    try {
      $reflector = new ReflectionClass($class);
    } catch (ReflectionException $e) {
      throw new ClassNotFoundException($e->getMessage(), previous: $e);
    }
    $constructor = $reflector->getConstructor();
    try {
      $args = $constructor ? $this->injectParams($constructor, $params) : [];
    } catch (ValidateException $e) {
      $this->handleValidateError(
        $reflector->getName() . '::__construct(): ' . $e->getMessage(), $e
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
    /** @var $args array<int,mixed> 最终要注入的参数 */
    $args = [];
    foreach ($shapes as $index => $shape) {
      // 参数类型
      $paramType = $shape->getType();
      // 扩展属性
      $attributes = $shape->getAttributes();
      // 是否允许为null
      $allowsNull = $shape->allowsNull();
      // 参数名称
      $name = $shape->getName();
      // 如果是可变参数则返回参数数组
      if ($shape->isVariadic()) {
        foreach ($params as &$item) {
          $item = $this->validateParam(
            $paramType, $item, $index, $name, $allowsNull, $attributes
          );
        }
        return array_merge($args, $params);
      }
      // 先判断是否存在命名，不存在则使用位置
      $key = array_key_exists($name, $params) ? $name : $index;
      // 参数默认值
      $default = $shape->isDefaultValueAvailable() ? $shape->getDefaultValue() : null;
      // 获得值
      $value = Arr::arrayPopValue($params, $key, $default);
      // 验证类型
      $value = $this->validateParam(
        $paramType,
        $value,
        $index,
        $name,
        $allowsNull,
        $attributes
      );
      $args[$index] = $value;
    }
    return $args;
  }

  /**
   * 验证参数
   *
   * @param ReflectionType|null $paramType 参数类型
   * @param mixed $value 值
   * @param int|string $index 索引
   * @param string $name 参数名称
   * @param bool $allowsNull 是否允许为空
   * @param array $attributes 扩展属性
   * @return mixed
   */
  protected function validateParam(
    ReflectionType|null $paramType,
    mixed               $value,
    int|string          $index,
    string              $name,
    bool                $allowsNull,
    array               $attributes
  ): mixed
  {
    if (!is_null($paramType)) {
      // 如果$value等于null 且设置的是内置类型 则判断是否允许为null，如果允许则返回null，否则抛出异常
      if (is_null($value) && $paramType->isBuiltin()) {
        if ($allowsNull) return null;
        $this->handleParamsError($index, $name, "must be of type $paramType, null given");
      }
      // 进行类型验证
      try {
        $value = Validate::check($value, $paramType);
      } catch (ValidateException $e) {
        $this->handleParamsError($index, $name, $e);
      }
    }
    try {
      // 验证扩展规则
      if (!empty($attributes)) $value = Validate::checkRules($attributes, $value);
    } catch (ValidateException $e) {
      $this->handleParamsError($index, $name, $e);
    }
    return $value;
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
   * @param callable|string|array $callable
   * @param array $params
   * @return mixed
   * @throws NotFoundException|TypeError
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
   * @param array|string $method 方法[object|class,method]|class::method
   * @param array $params 参数
   * @return mixed
   * @throws NotFoundException
   */
  public function invokeMethod(array|string $method, array $params = []): mixed
  {
    try {
      if (is_array($method)) {
        // 创建实例
        $instance = is_object($method[0]) ? $method[0] : $this->invokeClass($method[0]);
        $reflect = new ReflectionMethod($instance, $method[1]);
        $namespaceName = get_class($instance) . '::' . $method[1];
      } else {
        $instance = null;
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
   *  $container->bind(ExampleInterface::class, ExampleClass::class);
   * ```
   * @param string $abstract 接口|类名
   * @param string|object|null $concrete 实现类|实例|闭包
   * @return void
   */
  public function bind(string $abstract, string|object $concrete = null): void
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
