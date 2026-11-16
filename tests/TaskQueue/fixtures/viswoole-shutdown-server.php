<?php

declare(strict_types=1);

/* 职责：运行真实多进程 Swoole 服务，任务睡眠模拟正在执行的 I/O 工作。 */
require __DIR__ . '/shutdown-bootstrap.php';

use Viswoole\Core\Server\ServerEventHook;
use Viswoole\Core\Server\Shutdown\ShutdownPolicy;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
use Viswoole\Core\Server\Task\TaskQueueRecoveryTimer;

$directory = $argv[1];
$duration = (float)$argv[2];
$grace = (int)$argv[3];
$GLOBALS['shutdownDirectory'] = $directory;
define('SERVER_NAME', 'stop_test');
ShutdownRuntime::prepare();
$server = new Swoole\Server('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set(ShutdownPolicy::normalizeOptions([
  'worker_num' => 1, 'task_worker_num' => 1, 'task_enable_coroutine' => true,
  'max_wait_time' => $grace, 'pid_file' => $directory . '/stop_test.pid',
  'log_file' => $directory . '/swoole.log', 'log_level' => SWOOLE_LOG_WARNING,
]));
ServerEventHook::addEvent('receive', static function (): void {});
ServerEventHook::addEvent('finish', static function (): void {});
ServerEventHook::addEvent('workerstart', static function (Swoole\Server $server, int $workerId): void {
  if ($server->taskworker || $workerId !== 0) return;
  // 验证框架的后台恢复定时器不会把空闲 Worker 拖到超时。
  TaskQueueRecoveryTimer::start(30000, static function (): void {});
  Swoole\Timer::after(50, static function () use ($server): void { $server->task('running'); });
});
ServerEventHook::addEvent('task', static function () use ($directory, $duration): array {
  file_put_contents($directory . '/task-started', '1');
  Swoole\Coroutine::sleep($duration);
  file_put_contents($directory . '/task-completed', '1');
  return ['completed' => true];
});
foreach (ServerEventHook::getEventHooks() as $event => $handler) $server->on($event, $handler);
$server->start();
