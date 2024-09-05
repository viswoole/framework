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
  use Discover;

  /**
   * @inheritDoc
   */
  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $enterPath = getRootPath() . '/vendor/commands.php';
    $commandCount = $this->discover(
      'commands', '// 此文件为是command:discover处理脚本自动生成的注册文件:', $enterPath
    );
    $io->success("已生成命令注册文件 $enterPath 共计发现 $commandCount 个命令");
    return Command::SUCCESS;
  }
}
