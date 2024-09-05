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

namespace Viswoole\Core\Console\Commands\Service;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Viswoole\Core\Console\Commands\Discover;

/**
 * 发现服务
 */
#[AsCommand(
  name       : 'service:discover',
  description: 'Automatically scans the services provided in the dependency package and generates a service registration file.',
  hidden     : false
)]
class ServiceDiscover extends Command
{
  use Discover;

  /**
   * @inheritDoc
   */
  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $enterPath = getRootPath() . '/vendor/services.php';
    $serviceCount = $this->discover(
      'services', '// 此文件为是service:discover处理脚本自动生成的服务注册文件:', $enterPath
    );
    $io->success("已生成服务注册文件 $enterPath 共计发现 $serviceCount 个服务");
    return Command::SUCCESS;
  }
}
