<?php
/**
 * 职责：管理队列恢复扫描定时器及退出清理；不处理任务状态与业务消费。
 */

declare(strict_types=1);

namespace Viswoole\Core\Server\Task;

use Closure;
use RuntimeException;
use Swoole\Timer;
use Throwable;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;

/**
 * 队列周期恢复的错峰定时器边界；不负责缓存认领或业务投递。
 */
final class TaskQueueRecoveryTimer
{
  /**
   * @var list<int> 当前 Worker 拥有的队列定时器
   */
  private static array $timerIds = [];
  /**
   * 以严格正延时启动周期扫描，并记录定时器创建失败。
   * @param int $interval 扫描周期，单位毫秒
   * @param Closure $recover 恢复扫描回调
   * @return void
   */
  public static function start(int $interval, Closure $recover): void
  {
    try {
      $timerId = Timer::after(random_int(1, $interval), static function () use ($interval, $recover): void {
        if (ShutdownRuntime::isStopping()) return;
        try {
          $tickId = Timer::tick($interval, $recover);
          if ($tickId === false) {
            throw new RuntimeException('创建任务队列周期扫描定时器失败');
          }
          self::$timerIds[] = $tickId;
        } catch (Throwable $exception) {
          TaskQueueLog::write('任务队列周期扫描未启动', ['exception' => (string)$exception]);
        }
      });
      if ($timerId === false) throw new RuntimeException('创建任务队列错峰定时器失败');
      self::$timerIds[] = $timerId;
    } catch (Throwable $exception) {
      TaskQueueLog::write('任务队列错峰扫描未启动', ['exception' => (string)$exception]);
    }
  }

  /**
   * Worker 退出前停止后台重投，避免拖满优雅退出窗口。
   * @return void
   */
  public static function stop(): void
  {
    foreach (self::$timerIds as $timerId) {
      if (Timer::exists($timerId)) Timer::clear($timerId);
    }
    self::$timerIds = [];
  }
}
