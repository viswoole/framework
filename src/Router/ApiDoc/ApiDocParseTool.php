<?php /*
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
/** @noinspection DuplicatedCode */
declare (strict_types=1);

namespace Viswoole\Router\ApiDoc;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

/**
 * 文档解析工具
 *
 * 用于将路由配置转换为成文档结构
 */
class ApiDocParseTool
{

  /**
   * @param callable $handler
   * @return void
   */
  public static function parse(callable $handler)
  {
    $reflector = self::toReflector($handler);
    var_dump($reflector->getDocComment());
  }

  /**
   * 将处理方法转换为反射对象
   *
   * @param array|callable $callable
   * @return ReflectionMethod|ReflectionFunction
   */
  private static function toReflector(array|callable $callable): ReflectionMethod|ReflectionFunction
  {
    try {
      if ($callable instanceof Closure) {
        $reflection = new ReflectionFunction($callable);
      } elseif (is_string($callable)) {
        if (str_contains($callable, '::')) {
          $reflection = new ReflectionMethod($callable);
        } else {
          $reflection = new ReflectionFunction($callable);
        }
      } else {
        $reflection = new ReflectionMethod($callable[0], $callable[1]);
      }
      return $reflection;
    } catch (ReflectionException $e) {
      throw new RuntimeException('处理方法解析失败:' . $e->getMessage(), previous: $e);
    }
  }
}
