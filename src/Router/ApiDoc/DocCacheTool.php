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

namespace Viswoole\Router\ApiDoc;

/**
 * 该类用于文档缓存
 */
class DocCacheTool
{
  const string CACHE_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'RuntimeCache' . DIRECTORY_SEPARATOR;

  /**
   * 获取缓存
   *
   * @param string $key
   * @return mixed
   */
  public static function getCache(string $key): mixed
  {
    $file = self::CACHE_DIR . md5($key);
    if (!file_exists($file)) return null;
    $content = file_get_contents(self::CACHE_DIR . md5($key));
    if (!$content) return null;
    return unserialize(file_get_contents(self::CACHE_DIR . md5($key)));
  }

  /**
   * 删除缓存文件
   *
   * @param string $key
   * @return void
   */
  public static function deleteCache(string $key): void
  {
    $file = self::CACHE_DIR . md5($key);
    if (file_exists($file)) unlink($file);
  }

  /**
   * 缓存到文件
   *
   * @param string $key
   * @param mixed $value
   * @return void
   */
  public static function cache(string $key, mixed $value): void
  {
    $key = md5($key);
    if (!is_dir(dirname(self::CACHE_DIR))) {
      mkdir(self::CACHE_DIR, 0755);
    }
    file_put_contents(self::CACHE_DIR . $key, serialize($value));
  }
}
