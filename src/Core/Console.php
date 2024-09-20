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

namespace Viswoole\Core;

use InvalidArgumentException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Viswoole\Core\Console\Commands\CommandDiscover;
use Viswoole\Core\Console\Commands\Optimize\Facade;
use Viswoole\Core\Console\Commands\Server\ServerClose;
use Viswoole\Core\Console\Commands\Server\ServerReload;
use Viswoole\Core\Console\Commands\Server\ServerStart;
use Viswoole\Core\Console\Commands\Service\ServiceDiscover;
use Viswoole\Core\Console\Commands\Vendor\VendorPublish;

/**
 * 控制台命令行处理程序
 */
class Console extends Application
{
  protected array $defaultCommands = [
    Facade::class,
    ServerStart::class,
    ServerClose::class,
    ServerReload::class,
    CommandDiscover::class,
    ServiceDiscover::class,
    VendorPublish::class
  ];

  public function __construct()
  {
    parent::__construct('viswoole', getVersion());
    $this->loadCommand();
  }

  /**
   * 加载命令
   * @return void
   */
  protected function loadCommand(): void
  {
    $config = config('app.commands', []);
    $depPath = getVendorPath() . DIRECTORY_SEPARATOR . 'commands.php';
    // 依赖包注册的服务
    $dependentCommands = is_file($depPath) ? require $depPath : [];
    // 合并
    $config = array_merge($this->defaultCommands, $config, $dependentCommands);
    foreach ($config as $class) $this->addCommand($class);
  }

  /**
   * 添加一个命令行处理程序
   *
   * @access public
   * @param Command|string $command
   * @return Command|null
   * @throws InvalidArgumentException 传入的参数不是命令处理类
   */
  public function addCommand(Command|string $command): ?Command
  {
    if (is_string($command)) {
      if (!class_exists($command)) {
        throw new InvalidArgumentException("无法找到 $command 命令处理类");
      }
      $command = invoke($command);
    }
    if (!$command instanceof Command) {
      throw new InvalidArgumentException('注册的命令处理类必须继承自 ' . Command::class);
    }
    return $this->add($command);
  }
}
