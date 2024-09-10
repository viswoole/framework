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

namespace Viswoole\Core\Facade;

use Override;
use Viswoole\Core\Facade;
use Viswoole\Core\Server\TaskManager;

/**
 * 任务管理器门面类
 *
 * 注意：使用任务管理服务必须配置服务选项：
 * `Constant::OPTION_TASK_USE_OBJECT => true` 或 `Constant::OPTION_TASK_ENABLE_COROUTINE => true`
 *
 * @method static int|false emit(string $topic, mixed $data, bool $queue = true) 异步任务投递
 * @method static void has(string $topic) 判断是否存在任务主题
 * @method static void register(string $topic, callable|string $handle) 注册任务主题
 * @method static mixed|false emitWait(string $topic, mixed $data, float $timeout = 0.5) 同步阻塞等待任务执行完成，返回执行结果。返回false为执行失败
 * @method static array emitsWait(array $tasks, float $timeout = 0.5, bool $isCo = false) 同步阻塞等待执行多个任务，返回执行结果。返回false为执行失败
 *
 * 优化命令：php viswoole optimize:facade Viswoole\\Core\\Facade\\Task
 */
class Task extends Facade
{

  /**
   * @inheritDoc
   */
  #[Override] protected static function getMappingClass(): string
  {
    return TaskManager::class;
  }
}
