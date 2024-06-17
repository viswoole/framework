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
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use TypeError;
use Viswoole\Core\Common\Arr;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Core\Exception\ClassNotFoundException;
use Viswoole\Core\Exception\FuncNotFoundException;
use Viswoole\Core\Exception\MethodNotFoundException;
use Viswoole\Core\Exception\NotFoundException;

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
    $args = $this->injectParams($reflect, $params);
    return $reflect->invoke(...$args);
  }

  /**
   * 注入参数
   *
   * @param ReflectionFunctionAbstract $reflect 反射方法
   * @param array $params 传递的参数
   * @return array<int,mixed>
   * @throws NotFoundException
   */
  private function injectParams(ReflectionFunctionAbstract $reflect, array $params = []): array
  {
    $shapes = $reflect->getParameters();
    // 如果没有参数 则返回空待注入参数数组
    if (empty($shapes)) return [];
    /** @var $args array<int,mixed> 最终要注入的参数 */
    $args = [];
    foreach ($shapes as $index => $shape) {
      // 如果是可变参数则返回参数数组
      if ($shape->isVariadic()) return array_merge($args, $params);
      /** 参数类型 */
      $paramType = $shape->getType();
      // 参数名称
      $name = $shape->getName();
      // 先判断是否存在命名，不存在则使用位置
      $key = array_key_exists($name, $params) ? $name : $index;
      // 参数默认值
      $default = $shape->isDefaultValueAvailable() ? $shape->getDefaultValue() : null;
      if (is_null($paramType)) {
        $value = Arr::arrayPopValue($params, $key, $default);
      } elseif ($paramType instanceof ReflectionNamedType) {
        $value = $this->injectValue($params, $paramType, $key, $default);
      } elseif (
        $paramType instanceof ReflectionUnionType
        || $paramType instanceof ReflectionIntersectionType
      ) {
        // 联合类型直接获取
        $value = Arr::arrayPopValue($params, $key, $default);
      } else {
        $value = $default;
      }
      $args[$index] = $value;
    }
    return $args;
  }

  /**
   * 注入参数值
   *
   * @param array $vars 传递的参数数组
   * @param ReflectionNamedType $paramType 参数类型
   * @param string|int $key 参数名称
   * @param mixed|null $default 默认值
   * @return mixed
   * @throws NotFoundException
   */
  private function injectValue(
    array               &$vars,
    ReflectionNamedType $paramType,
    string|int          $key,
    mixed               $default
  ): mixed
  {
    $value = Arr::arrayPopValue($vars, $key, $default);
    if (!$paramType->isBuiltin()) {
      $class = $paramType->getName();
      // 判断是否直接传入了需要注入的类实例
      if ($value instanceof $class) return $value;
      // 依赖注入
      $value = $this->make($class);
    }
    return $value;
  }

  /**
   * @param string $abstract
   * @param array $params
   * @return object
   * @throws NotFoundException
   */
  public function make(string $abstract, array $params = []): object
  {
    // 获取绑定
    $abstract = $this->getBind($abstract);
    if (is_string($abstract)) {
      $instance = $this->getSingleton($abstract);
      if ($instance) return $instance;
      $instance = $this->invokeClass($abstract, $params);
    } else {
      $instance = $this->invokeFunction($abstract, $params);
    }
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
  private function getBind(string $abstract): string|Closure
  {
    return $this->bindings[$abstract] ?? $abstract;
  }

  /**
   * 获取单实例
   *
   * @param string $class
   * @return object|null
   */
  private function getSingleton(string $class): ?object
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
   * 判断容器中是否绑定某个接口
   *
   * @param string $id
   * @return bool
   */
  public function has(string $id): bool
  {
    return isset($this->bindings[$id]);
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
      $constructor = $reflector->getConstructor();
      $args = $constructor ? $this->injectParams($constructor, $params) : [];
      $instance = $reflector->newInstanceArgs($args);
      $this->invokeAfter($class, $instance);
      return $instance;
    } catch (ReflectionException $e) {
      throw new ClassNotFoundException($e->getMessage(), previous: $e);
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
   * 设置单实例（存储在父协程中），协程结束自动销毁
   *
   * @param string $class
   * @param object $instance
   * @return void
   */
  private function setSingleInstance(string $class, object $instance): void
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
   * 调用反射执行方法，支持依赖注入。
   *
   * @access public
   * @param array|string $method 方法[class,method]|class::method
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
      } else {
        $instance = null;
        $reflect = new ReflectionMethod($method);
      }
      // 绑定参数
      $args = $this->injectParams($reflect, $params);
      // 调用方法并传入参数
      return $reflect->invokeArgs($instance, $args);
    } catch (ReflectionException $e) {
      throw new MethodNotFoundException($e->getMessage(), previous: $e);
    }
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
      $class = get_class($concrete);
      $this->bindings[$abstract] = $class;
      $this->instances[$class] = $concrete;
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
}
