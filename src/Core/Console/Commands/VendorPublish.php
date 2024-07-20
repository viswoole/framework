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

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Viswoole\Core\Facade\App;

#[AsCommand(
  name       : 'vendor:publish',
  description: 'Automatically scans the configurations provided in the dependency package and clones the configuration files to the config/autoload directory at the root of the project.',
  hidden     : false
)]
class VendorPublish extends Command
{
  #[Override] protected function configure(): void
  {
    $this->addOption(
      'force',
      'f',
      InputOption::VALUE_NONE,
      'Force overwrite writes'
    );
  }

  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $force = $input->getOption('force');
    $vendorDir = App::getVendorPath();
    $configDir = App::getConfigPath();
    $installedPath = App::getVendorPath() . '/composer/installed.json';
    $io = new SymfonyStyle($input, $output);
    if (is_file($installedPath)) {
      $packages = json_decode(file_get_contents($installedPath), true);
      // Compatibility with Composer 2.0
      if (isset($packages['packages'])) $packages = $packages['packages'];

      $configs = [];
      $vendorCount = 0;
      foreach ($packages as $package) {
        if (!empty($package['extra']['viswoole']['configs'])) {
          $vendorCount++;
          $packageConfigs = (array)$package['extra']['viswoole']['configs'];
          foreach ($packageConfigs as &$c) {
            $c = $vendorDir . '/' . $package['name']
              . (str_starts_with($c, '/') ? $c : '/' . $c);
          }
          $configs = array_merge($configs, $packageConfigs);
        }
      }
      $configFiles = [];
      foreach ($configs as $config) {
        if (is_dir($config)) {
          $files = $this->getAllFiles($config);
          $configFiles = array_merge($configFiles, $files);
        } elseif (is_file($config)) {
          $configFiles[] = $config;
        }
      }
      $count = 0;
      foreach ($configFiles as $file) {
        $destinationDir = $configDir . '/' . basename($file);
        if ($force || !file_exists($destinationDir)) {
          $count++;
          copy($file, $configDir . '/' . basename($file));
        }
      }
      $io->success(
        "已完成{$vendorCount}个依赖包发布，共计发布 $count 个配置文件至{$configDir}目录下"
      );
    }
    return Command::SUCCESS;
  }

  /**
   * 获取目录下的所有文件
   *
   * @param string $dir
   * @return array
   */
  private function getAllFiles(string $dir): array
  {
    $files = [];

    // 打开目录
    if ($handle = opendir($dir)) {
      $dir = rtrim($dir, DIRECTORY_SEPARATOR);
      // 逐个检查目录中的条目
      while (false !== ($entry = readdir($handle))) {
        if ($entry != '.' && $entry != '..') {
          $path = $dir . '/' . $entry;
          // 如果是目录，递归调用该函数
          if (is_dir($path)) {
            $files = array_merge($files, $this->getAllFiles($path));
          } else {
            // 如果是.php文件，添加到结果数组中
            $files[] = $path;
          }
        }
      }
      // 关闭目录句柄
      closedir($handle);
    }
    return $files;
  }
}
