<?php
/*
 *  +----------------------------------------------------------------------
 *  | ViSwoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Core\Console\Server;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use ViSwoole\Core\Server\Action as ServerAction;

/**
 * 启动服务
 */
#[AsCommand(
  name       : 'server:start',
  description: 'Start a server.',
  hidden     : false
)]
class ServerStart extends Command
{
  protected function configure(): void
  {
    $this->addArgument(
      'service',
      InputArgument::OPTIONAL,
      'Name of the service to start',
      config('server.default_start_server', 'http')
    );
    $this->addOption(
      'force',
      'f',
      InputOption::VALUE_NONE,
      'Force the service to start.'
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
    $daemonize = $input->getOption('daemonize');
    $io = new SymfonyStyle($input, $output);
    try {
      ServerAction::start($service, $force, $daemonize);
    } catch (Throwable $e) {
      $io->error($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
      return Command::FAILURE;
    }
    return Command::SUCCESS;
  }
}
