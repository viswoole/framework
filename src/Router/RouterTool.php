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

use Viswoole\Router\Route\Group;

/**
 * 路由器工具类
 *
 * 存放了一些工具方法
 */
class RouterTool
{
  /**
   * 获取缓存
   *
   * @param string $server 服务名称
   * @param string $controller 控制器类完全名称，包含命名空间
   * @param string $hash 类文件哈希值，如果不匹配，则返回null
   * @return null|Group
   */
  public static function getCache(string $server, string $controller, string $hash): ?Group
  {
    $file = self::generateCacheFileName($server, $controller);
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    if (!$content) return null;
    $cacheData = unserialize(file_get_contents($file));
    if (!is_array($cacheData)) return null;
    if (($cacheData['hash'] ?? null) !== $hash) {
      unlink($file);
      return null;
    }
    return $cacheData['route'] ?? null;
  }

  /**
   * 生成控制器缓存文件名称
   *
   * @param string $server
   * @param string $controller
   * @return string
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
   * 递归删除目录及其内容
   *
   * @param string $dir 目录路径
   * @return int
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
   * 获取目录下所有指定后缀的文件
   *
   * @param string $dir 文件目录
   * @param string $ext 文件后缀
   * @param bool $recursion 是否递归子目录
   * @return array
   */
  public static function getAllFiles(
    string $dir, string $ext = 'php',
    bool   $recursion = true
  ): array
  {
    $phpFiles = [];
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
   * 判断是否为可选变量
   * @param string $str
   * @return bool
   */
  public static function isOptionalVariable(string $str): bool
  {
    return preg_match('/^\{[^}]+\?}$/', $str) === 1;
  }

  /**
   * 判断字符串中是否包含{}包裹的变量
   *
   * @param string $str
   * @return bool
   */
  public static function isVariable(string $str): bool
  {
    return preg_match('/\{[^}]+\??}/', $str) === 1;
  }

  /**
   * 获取控制器完全限定名称
   *
   * @param string $controller
   * @param string $rootPath
   * @return array{0:string,1:string} [0=>完全限定名称,1=>类名称]
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
