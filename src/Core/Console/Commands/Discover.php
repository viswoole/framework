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

namespace Viswoole\Core\Console\Commands;

/**
 * 发现命令
 */
trait Discover
{
  /**
   * 发现
   *
   * @param string $name
   * @param string $describe
   * @param string $enterPath
   * @return int
   */
  protected function discover(string $name, string $describe, string $enterPath): int
  {
    $path = getRootPath() . '/vendor/composer/installed.json';
    if (is_file($path)) {
      $packages = json_decode(file_get_contents($path), true);
      // Compatibility with Composer 2.0
      if (isset($packages['packages'])) $packages = $packages['packages'];
      $services = [];
      foreach ($packages as $package) {
        if (!empty($package['extra']['viswoole'][$name])) {
          $services = array_merge($services, (array)$package['extra']['viswoole'][$name]);
        }
      }
      $header = $describe
        . date('Y-m-d H:i:s') . PHP_EOL
        . 'declare (strict_types=1);' . PHP_EOL . PHP_EOL;
      $content = 'return [' . PHP_EOL;
      foreach ($services as $service) {
        $service = str_replace("'", '"', var_export($service, true));
        $content .= "  $service," . PHP_EOL;
      }
      $content = rtrim($content, ',' . PHP_EOL) . PHP_EOL . '];';
      $content = '<?php' . PHP_EOL . $header . $content;
      file_put_contents($enterPath, $content);
      return count($services);
    } else {
      return 0;
    }
  }
}
