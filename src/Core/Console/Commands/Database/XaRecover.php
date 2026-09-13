<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Core\Console\Commands\Database;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Viswoole\Database\Transaction\XaRecovery;

/**
 * 手动执行 XA 崩溃恢复
 *
 * 服务运行期间发生 XA 事务未决（如提交阶段进程异常但服务未重启）时，
 * 无需重启服务即可通过本命令立即收敛；worker 启动时的自动恢复
 * （见 DbService）覆盖重启场景，本命令是其手动补充。
 * 支持 --dry-run 预览处置计划、--xid 仅收敛指定事务。
 */
#[AsCommand(
  name       : 'xa:recover',
  description: 'Manually run XA crash recovery.',
  hidden     : false
)]
class XaRecover extends Command
{
  /**
   * @inheritDoc
   */
  protected function configure(): void
  {
    $this->addOption(
      'xid',
      null,
      InputOption::VALUE_REQUIRED,
      '仅恢复指定的全局事务 ID（gtrid，journal 行主键，非分支 xid）'
    );
    $this->addOption(
      'dry-run',
      null,
      InputOption::VALUE_NONE,
      '仅扫描并报告将执行的动作，不实际终结分支或删除 journal 行'
    );
  }

  /**
   * @inheritDoc
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    try {
      XaRecovery::run($input->getOption('xid'), (bool)$input->getOption('dry-run'));
    } catch (Throwable $e) {
      $io->error(
        $e->getMessage() . ' in '
        . $e->getFile() . ' on line '
        . $e->getLine() . PHP_EOL
        . $e->getTraceAsString()
      );
      return Command::FAILURE;
    }
    $io->success('XA 崩溃恢复执行完成（journal 无未决事务时无任何操作）');
    return Command::SUCCESS;
  }
}
