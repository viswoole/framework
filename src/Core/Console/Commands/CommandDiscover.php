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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 发现命令
 */
#[AsCommand(
  name       : 'command:discover',
  description: 'Automatically scans the command provided in the dependency package and generates a service registration file.',
  hidden     : false
)]
class CommandDiscover extends Command
{
  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $path = getRootPath() . '/vendor/composer/installed.json';
    $io = new SymfonyStyle($input, $output);
    if (is_file($path)) {
      $packages = json_decode(file_get_contents($path), true);
      // Compatibility with Composer 2.0
      if (isset($packages['packages'])) $packages = $packages['packages'];

      $services = [];
      foreach ($packages as $package) {
        if (!empty($package['extra']['viswoole']['commands'])) {
          $services = array_merge($services, (array)$package['extra']['viswoole']['commands']);
        }
      }

      $header = '// 此文件为由command:discover命令处理程序自动生成的服务注册文件:' . date(
          'Y-m-d H:i:s'
        ) . PHP_EOL
        . 'declare (strict_types=1);' . PHP_EOL . PHP_EOL;
      $content = 'return [' . PHP_EOL;
      foreach ($services as $command) {
        $command = str_replace("'", '"', var_export($command, true));
        $content .= "  $command," . PHP_EOL;
      }
      $content = rtrim($content, ',' . PHP_EOL) . PHP_EOL . '];';
      $content = '<?php' . PHP_EOL . $header . $content;
      $enterPath = getRootPath() . '/vendor/commands.php';
      file_put_contents($enterPath, $content);
      $commandCount = count($services);
      $io->success("已生成命令注册文件 $enterPath 共计发现 $commandCount 个命令");
    }
    return Command::SUCCESS;
  }
}
