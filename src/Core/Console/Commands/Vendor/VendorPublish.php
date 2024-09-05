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

namespace Viswoole\Core\Console\Commands\Vendor;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Viswoole\Core\Facade\App;

/**
 * 自动发布依赖包配置文件
 */
#[AsCommand(
  name       : 'vendor:publish',
  description: 'Automatically scans the files or folders provided in the dependency package and clones them to the root of the project, keeping the directory hierarchy intact.',
  hidden     : false
)]
class VendorPublish extends Command
{
  /**
   * @inheritDoc
   *
   * @return void
   */
  #[Override] protected function configure(): void
  {
    $this->addOption(
      'force',
      'f',
      InputOption::VALUE_NONE,
      'Force overwrite writes'
    );
  }

  /**
   * @inheritDoc
   */
  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $force = $input->getOption('force');
    $vendorPath = App::getVendorPath();
    $rootPath = App::getRootPath();
    $installedFilePath = App::getVendorPath() . '/composer/installed.json';
    $io = new SymfonyStyle($input, $output);
    if (is_file($installedFilePath)) {
      $packages = json_decode(file_get_contents($installedFilePath), true);
      // Compatibility with Composer 2.0
      if (isset($packages['packages'])) $packages = $packages['packages'];
      $configs = [];
      $vendorCount = 0;
      $count = 0;
      foreach ($packages as $package) {
        if (!empty($package['extra']['viswoole']['configs'])) {
          $vendorCount++;
          $extraConfigs = (array)$package['extra']['viswoole']['configs'];
          foreach ($extraConfigs as $path) {
            $path = str_starts_with(
              $path, DIRECTORY_SEPARATOR
            ) ? $path : DIRECTORY_SEPARATOR . $path;
            $packagePath = $vendorPath . '/' . $package['name'];
            $files = $this->getAllFiles($vendorPath . '/' . $package['name'] . $path);
            $count += $this->copy($rootPath, $packagePath, $files, $force);
          }
        }
      }
      $io->success(
        "已完成{$vendorCount}个依赖包发布，共计发布 $count 个文件。"
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

  /**
   * 把依赖包中的配置文件或其他模板文件复制到根目录下对应目录
   *
   * @param string $rootPath
   * @param string $packagePath
   * @param array $files
   * @param bool $force
   * @return int
   */
  private function copy(string $rootPath, string $packagePath, array $files, bool $force): int
  {
    $count = 0;
    foreach ($files as $file) {
      $destinationPath = str_replace($packagePath, $rootPath, $file);
      // 获取目标目录
      $destinationDirName = dirname($destinationPath);
      // 检查目标目录是否存在，如果不存在则创建
      if (!is_dir($destinationDirName)) {
        mkdir($destinationDirName, 0755, true); // 递归创建目录
      }
      if ($force || !file_exists($destinationPath)) {
        copy($file, $destinationPath);
        $count++;
      }
    }
    return $count;
  }
}
