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

/**
 * 文档解析工具
 *
 * 用于将路由配置转换为成文档结构
 */
class RouteCacheTool
{
  private static string $cachePath;

  /**
   * 获取缓存
   *
   * @param string $controller 控制器类完全名称，包含命名空间
   * @param string $hash 类文件哈希值，如果不匹配，则返回null
   * @return null|array{server: string|null, route: RouteGroup}
   */
  public static function getCache(string $controller, string $hash): mixed
  {
    $file = self::generateCacheFileName($controller);
    if (!file_exists($file)) return null;
    $content = file_get_contents($file);
    if (!$content) return null;
    $cacheData = unserialize(file_get_contents($file));
    if (!is_array($cacheData)) return null;
    if (($cacheData['hash'] ?? null) !== $hash) {
      self::remove($controller);
      return null;
    }
    return $cacheData['data'] ?? null;
  }

  /**
   * 生成控制器缓存文件名称
   *
   * @param string $controller
   * @return string
   */
  private static function generateCacheFileName(string $controller): string
  {
    return str_replace('\\', '_', $controller) . '.cache';
  }

  /**
   * 删除某个类的缓存路由表
   *
   * @param string $controller
   * @return void
   */
  public static function remove(string $controller): void
  {
    $file = self::generateCacheFileName($controller);
    if (file_exists($file)) unlink($file);
  }

  /**
   * 清除所有缓存
   *
   * @return void
   */
  public static function clear(): void
  {
    $dir = self::getCachePath();
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $file) {
      if ($file === '.' || $file === '..') continue;
      unlink($dir . '/' . $file);
    }
  }

  /**
   * 获取缓存路径
   *
   * @return string
   */
  public static function getCachePath(): string
  {
    if (!isset(self::$cachePath)) {
      self::$cachePath = dirname(config('router.cache.path', BASE_PATH . '/runtime/route'));
    }
    return self::$cachePath;
  }

  /**
   * 缓存到文件
   *
   * @param string $controller 控制器类完全名称，包含命名空间
   * @param string $hash 类文件哈希值
   * @param string|null $server 服务名称
   * @param RouteGroup $groupRoute 路由组
   * @return void
   */
  public static function setCache(
    string     $controller,
    string     $hash,
    ?string    $server,
    RouteGroup $groupRoute
  ): void
  {
    $fileName = self::generateCacheFileName($controller);
    if (!is_dir(dirname(self::getCachePath()))) {
      mkdir(self::getCachePath(), 0755);
    }
    file_put_contents(
      self::getCachePath() . $fileName,
      serialize(['hash' => $hash, 'data' => ['server' => $server, 'route' => $groupRoute]])
    );
  }
}
