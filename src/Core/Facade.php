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
 * 门面抽象类
 */
abstract class Facade
{
  /**
   * 始终创建新的对象实例
   * @var bool
   */
  protected static bool $alwaysNewInstance = false;

  /**
   * @param $method
   * @param $params
   * @return mixed
   */
  public static function __callStatic($method, $params)
  {
    return call_user_func_array([static::createFacade(), $method], $params);
  }

  /**
   * 创建Facade实例
   * @static
   * @access protected
   * @return object
   */
  protected static function createFacade(): object
  {
    if (self::$alwaysNewInstance) {
      return App::factory()->invokeClass(static::getMappingClass());
    } else {
      return App::factory()->make(static::getMappingClass());
    }
  }

  /**
   * 获取当前Facade对应类名
   *
   * @access protected
   * @return string
   */
  abstract protected static function getMappingClass(): string;
}
