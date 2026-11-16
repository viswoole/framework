<?php

/**
 * 职责：暴露任务管理器门面；不保存消费状态或执行处理器。
 */
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
use Viswoole\Core\Server\Task\TaskManager;

/**
 * 任务管理门面，静态代理 TaskManager 核心方法
 *
 * 注意：使用任务管理服务必须配置服务选项：
 * `Constant::OPTION_TASK_USE_OBJECT => true` 或 `Constant::OPTION_TASK_ENABLE_COROUTINE => true`
 *
 * @method static string|int|false emit(string $topic, mixed $data, bool $queue = true) 持久化返回稳定队列 ID，直投返回 Swoole ID；false 表示明确拒收，提交未知抛 TaskAcceptanceUnknownException
 * @method static string instanceId() 获取当前启动实例身份
 * @method static \Viswoole\Core\Server\Task\TaskQueueQuery queue() 获取只读元数据查询入口
 * @method static \Viswoole\Core\Server\Task\TaskFailedQueue failedQueue() 获取失败任务管理入口
 * @method static \Viswoole\Core\Server\Task\TaskQuarantinedQueue quarantinedQueue() 查询损坏记录原始快照
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
   * 获取门面代理的目标类名
   */
  #[Override] protected static function getMappingClass(): string
  {
    return TaskManager::class;
  }
}
