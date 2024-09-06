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

namespace Viswoole\Core\Facade;

use Closure;
use Override;
use Viswoole\Core\Facade;

/**
 * App应用管理中心
 *
 * @method static string getVendorPath() 获取vendor路径
 * @method static string getRootPath() 获取项目根路径
 * @method static \Viswoole\Core\App factory() 工厂单例模式
 * @method static string getConfigPath() 获取config路径
 * @method static string getAppPath() 获取app路径
 * @method static string getEnvPath() 获取env路径
 * @method static bool isDebug() 是否debug调试模式
 * @method static void setDebug(bool $debug) 设置是否启用debug模式，在请求中设置仅对当前请求的worker进程生效
 * @method static object make(string $abstract, array $params = []) 创建一个已绑定的服务，或反射创建类实例，将存储为单例
 * @method static object get(string $id) 从容器绑定中获取实例
 * @method static bool has(string $id) 判断容器中是否绑定某个接口
 * @method static mixed invoke(callable|string|array $callable, array $params = []) 调用反射执行函数、匿名函数、以及类或方法，支持依赖注入。
 * @method static mixed invokeFunction(Closure|string $concrete, array $params = []) 反射调用函数
 * @method static object invokeClass(string $class, array $params = []) 调用反射创建类实例，支持依赖注入。
 * @method static mixed invokeMethod(array|string $method, array $params = []) 调用反射执行方法，支持依赖注入。
 * @method static void addHook(string $abstract, Closure $callback) 添加一个钩子，在解析类时触发
 * @method static void removeHook(string $abstract, ?Closure $callback = null) 删除解析钩子
 * @method static void bind(string $abstract, object|string|null $concrete = null) 绑定接口
 * @method static void remove(string $abstract) 删除容器中的服务实例
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Core\\Facade\\App
 */
class App extends Facade
{
  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return \Viswoole\Core\App::class;
  }
}
