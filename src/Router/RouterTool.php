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
declare (strict_types=1);

namespace Viswoole\Router;

use Viswoole\Core\App;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\Status;
use Viswoole\Router\ApiDoc\Structure\ArrayTypeStructure;
use Viswoole\Router\ApiDoc\Structure\ClassTypeStructure;
use Viswoole\Router\ApiDoc\Structure\EnumStructure;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\ObjectStructure;
use Viswoole\Router\ApiDoc\Structure\TypeStructure;
use Viswoole\Router\ApiDoc\Structure\Types;
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;

/**
 * 路由器工具类
 *
 * 提供路由路径解析、缓存读写、控制器类名推导等静态工具方法
 */
class RouterTool
{
  /**
   * 路由缓存反序列化类白名单
   *
   * 缓存文件（runtime/route/*.cache）由 Web 进程写入，同权限进程可篡改；
   * 无白名单的 unserialize 会实例化任意类并触发 __wakeup/__destruct，
   * 构成 PHP 对象注入攻击面（CWE-502），仅允许恢复路由结构相关类
   */
  private const CACHE_ALLOWED_CLASSES = [
    Group::class,
    Route::class,
    Status::class,
    Types::class,
    Returned::class,
    FieldStructure::class,
    TypeStructure::class,
    ArrayTypeStructure::class,
    ClassTypeStructure::class,
    ObjectStructure::class,
    EnumStructure::class,
  ];
  /**
   * 将文件绝对路径转换为相对项目根目录的路径
   *
   * 用于接口文档展示源码位置，相对路径可跨环境（如容器内外）定位
   *
   * @param string $file 文件绝对路径
   * @return string 相对路径，不在根目录下时返回原路径
   */
  public static function relativeToRoot(string $file): string
  {
    $root = getRootPath();
    return $root !== '' && str_starts_with($file, $root . '/')
      ? substr($file, strlen($root) + 1)
      : $file;
  }

  /**
   * 生成路由缓存哈希
   *
   * 由框架版本号与控制器文件哈希共同决定：控制器文件变更或框架升级
   * 均会使缓存失效，避免框架升级后反序列化出缺字段的路由对象
   *
   * @param string $file 控制器文件绝对路径
   * @return string 缓存哈希值
   */
  public static function getCacheHash(string $file): string
  {
    return md5(App::VERSION . ':' . hash_file('md5', $file));
  }

  /**
   * 获取缓存
   *
   * @param string $server 服务名称
   * @param string $controller 控制器类完全名称，包含命名空间
   * @param string $hash 类文件哈希值，如果不匹配，则返回 null
   * @return null|Group
   */
  public static function getCache(string $server, string $controller, string $hash): ?Group
  {
    $file = self::generateCacheFileName($server, $controller);
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    if ($content === false || $content === '') return null;
    // 反序列化施加类白名单：缓存文件可被同权限进程篡改，任意类恢复会触发
    // POP 链（CWE-502）；白名单外的对象退化为 __PHP_Incomplete_Class，
    // 由下方 instanceof 校验统一判为无效缓存
    $cacheData = unserialize($content, ['allowed_classes' => self::CACHE_ALLOWED_CLASSES]);
    if (!is_array($cacheData)) return null;
    if (($cacheData['hash'] ?? null) !== $hash) {
      unlink($file);
      return null;
    }
    $route = $cacheData['route'] ?? null;
    return $route instanceof Group ? $route : null;
  }

  /**
   * 根据服务名和控制器类名生成缓存文件路径
   *
   * @param string $server 服务名称
   * @param string $controller 控制器类完全限定名称
   * @return string 缓存文件绝对路径
   */
  private static function generateCacheFileName(string $server, string $controller): string
  {
    return self::getCachePath($server) . DIRECTORY_SEPARATOR . str_replace(
        '\\', '_', $controller
      ) . '.cache';
  }

  /**
   * 获取缓存路径
   *
   * @param string|null $server
   * @return string
   */
  public static function getCachePath(?string $server): string
  {
    $dir = config('router.cache.path');
    if (!is_string($dir)) {
      $dir = BASE_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'route';
    } else {
      $dir = rtrim(trim($dir), '/');
    }
    if ($server) $dir .= DIRECTORY_SEPARATOR . $server;
    if (!is_dir($dir)) {
      mkdir($dir, 0755, true);
    }
    return $dir;
  }

  /**
   * 清除所有缓存
   *
   * @param string|null $server 服务名称
   * @return int
   */
  public static function clear(?string $server): int
  {
    $dir = self::getCachePath($server);
    if (!is_dir($dir)) return 0;
    return self::deleteDirectory($dir);
  }

  /**
   * 递归删除目录及其下所有文件
   *
   * @param string $dir 目录路径
   * @return int 删除的文件数量
   */
  private static function deleteDirectory(string $dir): int
  {
    $count = 0;
    if (!is_dir($dir)) return $count;
    $files = scandir($dir);
    foreach ($files as $file) {
      if ($file === '.' || $file === '..') continue;
      $path = $dir . '/' . $file;
      if (is_dir($path)) {
        $count += self::deleteDirectory($path);
      } else {
        $count++;
        unlink($path);
      }
    }
    rmdir($dir);
    return $count;
  }

  /**
   * 递归扫描目录下指定后缀的文件
   *
   * @param string $dir 目录路径
   * @param string $ext 文件后缀，默认 php
   * @param bool $recursion 是否递归子目录
   * @return array 文件路径列表
   */
  public static function getAllFiles(
    string $dir, string $ext = 'php',
    bool   $recursion = true
  ): array
  {
    $phpFiles = [];
    // 目录不存在时直接返回，避免 opendir 产生 PHP Warning
    // （如项目尚未创建 app/Controller 目录的初始化场景）
    if (!is_dir($dir)) return $phpFiles;
    // 打开目录
    if ($handle = opendir($dir)) {
      $dir = rtrim($dir, DIRECTORY_SEPARATOR);
      // 逐个检查目录中的条目
      while (false !== ($entry = readdir($handle))) {
        if ($entry != '.' && $entry != '..') {
          $path = $dir . '/' . $entry;
          // 如果是目录，递归调用该函数
          if (is_dir($path)) {
            // 如果递归获取子目录 则继续递归
            if ($recursion) {
              $phpFiles = array_merge($phpFiles, self::getAllFiles($path, $ext));
            }
          } elseif (pathinfo($path, PATHINFO_EXTENSION) == $ext) {
            // 如果是.php文件，添加到结果数组中
            $phpFiles[] = $path;
          }
        }
      }
      // 关闭目录句柄
      closedir($handle);
    }
    return $phpFiles;
  }

  /**
   * 缓存到文件
   *
   * @param string $server 服务名称
   * @param string $controller 控制器类完全名称，包含命名空间
   * @param string $hash 类文件哈希值
   * @param Group $groupRoute 路由组
   * @return void
   */
  public static function setCache(
    string $server,
    string $controller,
    string $hash,
    Group  $groupRoute
  ): void
  {
    $file = self::generateCacheFileName($server, $controller);
    file_put_contents($file, serialize(['hash' => $hash, 'route' => $groupRoute]));
  }

  /**
   * 生成哈希ID
   *
   * @param string $id
   * @return string
   */
  public static function generateHashId(string $id): string
  {
    // 将 MD5 哈希值转换为二进制字符串
    $binaryHash = pack('H*', md5($id));
    // 使用 Base64 对二进制字符串进行编码
    return base64_encode($binaryHash);
  }

  /**
   * 提取变量名称
   * @param string $routePattern
   * @return string
   */
  public static function extractVariableName(string $routePattern): string
  {
    return str_replace(['{', '}', '?', ' '], '', $routePattern);
  }

  /**
   * 判断路由模式段是否为可选变量（如 {id?}）
   *
   * @param string $str 路由模式段
   * @return bool
   */
  public static function isOptionalVariable(string $str): bool
  {
    return preg_match('/^\{[^}]+\?}$/', $str) === 1;
  }

  /**
   * 判断字符串中是否包含花括号包裹的路由变量（如 {id} 或 {id?}）
   *
   * @param string $str 待检测字符串
   * @return bool
   */
  public static function isVariable(string $str): bool
  {
    return preg_match('/\{[^}]+\??}/', $str) === 1;
  }

  /**
   * 根据控制器文件路径推导其完全限定类名
   *
   * @param string $controller 控制器文件绝对路径
   * @param string $rootPath 项目根目录
   * @return array{0:string,1:string} [0=>完全限定类名, 1=>类短名]
   */
  public static function getNamespace(string $controller, string $rootPath): array
  {
    // 获得类名称
    $className = basename($controller, '.php');
    // 获得命名空间
    $classNamespace = str_replace($rootPath, '', $controller);
    $classNamespace = preg_replace('#^app/#', 'App/', dirname($classNamespace));
    $classNamespace = str_replace('/', '\\', $classNamespace);
    // 类完全限定名称Class::class
    return [$classNamespace . '\\' . $className, $className];
  }

}
