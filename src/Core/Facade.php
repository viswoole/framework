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

/**
 * 门面抽象基类
 *
 * 通过 __callStatic 魔术方法将静态调用代理到容器中的实际服务实例，
 * 子类只需实现 getMappingClass() 声明映射的类名即可使用静态方式访问服务。
 */
abstract class Facade
{
  /**
   * @var bool 是否每次调用都创建新实例，为 false 时使用容器单例
   */
  protected static bool $alwaysNewInstance = false;

  /**
   * 静态方法代理，将调用转发到门面实例的对应方法
   *
   * @param string $method 方法名
   * @param array $params 方法参数
   * @return mixed 方法返回值
   */
  public static function __callStatic(string $method, array $params)
  {
    return call_user_func_array([static::createFacade(), $method], $params);
  }

  /**
   * 创建门面对应的服务实例，根据 $alwaysNewInstance 决定是否复用单例
   *
   * @return object 门面映射的服务实例
   */
  protected static function createFacade(): object
  {
    // 修复#1: self::改为static::，支持子类覆盖$alwaysNewInstance
    if (static::$alwaysNewInstance) {
      return App::factory()->invokeClass(static::getMappingClass());
    } else {
      return App::factory()->make(static::getMappingClass());
    }
  }

  /**
   * 获取当前门面映射的实际类名，由子类实现
   *
   * @return string 容器中注册的类名或标识
   */
  abstract protected static function getMappingClass(): string;
}
