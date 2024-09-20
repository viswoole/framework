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

namespace Viswoole\Router\Commands;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Viswoole\Router\RouterTool;

/**
 * 清理路由缓存
 */
#[AsCommand(
  name       : 'router:clear-cache',
  description: 'Clear routing caches',
  hidden     : false
)]
class ClearCache extends Command
{
  /**
   * @inheritDoc
   */
  #[Override] protected function configure(): void
  {
    $this->addArgument(
      'server',
      InputArgument::OPTIONAL,
      'If the corresponding server name is not transmitted, all server routing caches will be cleared.'
    );
  }

  /**
   * @inheritDoc
   */
  #[Override] protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $server = $input->getArgument('server');
    $io = new SymfonyStyle($input, $output);
    try {
      $count = RouterTool::clear($server);
      $io->success("共计清除 $count 个路由缓存文件");
    } catch (Throwable $e) {
      $io->error($e->getMessage());
      return Command::FAILURE;
    }
    return Command::SUCCESS;
  }
}
