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

namespace Viswoole\Core\Console\Commands\Server;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Viswoole\Core\Server\Action;

/**
 * 关闭服务
 */
#[AsCommand(
  name       : 'server:close',
  description: 'Close a server.',
  hidden     : false
)]
class ServerClose extends Command
{
  protected function configure(): void
  {
    $this->addArgument(
      'server',
      InputArgument::OPTIONAL,
      'Name of the server to close',
      config('server.default_start_server', 'http')
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $service = $input->getArgument('server');
    $io = new SymfonyStyle($input, $output);
    try {
      Action::close($service);
    } catch (Throwable $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }
    return Command::SUCCESS;
  }
}

