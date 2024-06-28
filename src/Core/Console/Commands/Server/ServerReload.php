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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Viswoole\Core\Server\Action;

/**
 * 重启服务
 */
#[AsCommand(
  name       : 'server:reload',
  description: 'Reload a server or task.',
  hidden     : false
)]
class ServerReload extends Command
{
  protected function configure(): void
  {
    $this->addArgument(
      'service',
      InputArgument::OPTIONAL,
      'Name of the service to reload',
      config('server.default_start_server', 'http')
    );
    $this->addOption(
      'task',
      't',
      InputOption::VALUE_NONE,
      'Reload only the task process'
    );
    $this->addOption(
      'force',
      'f',
      InputOption::VALUE_NONE,
      'Shut down all service processes and restart the entire service.'
    );
    $this->addOption(
      'daemonize',
      'd',
      InputOption::VALUE_NONE,
      'Daemonize the service to daemonize start.'
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $service = $input->getArgument('service');
    $force = $input->getOption('force');
    $io = new SymfonyStyle($input, $output);
    try {
      if ($force) {
        $daemonize = $input->getOption('daemonize');
        Action::start($service, $force, $daemonize);
      } else {
        $taskReload = $input->getOption('task');
        Action::reload($service, $taskReload);
      }
    } catch (Throwable $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }
    $io->success($force ? "{$service}服务进程强制重启成功" : "{$service}服务进程重启成功");
    return Command::SUCCESS;
  }
}
