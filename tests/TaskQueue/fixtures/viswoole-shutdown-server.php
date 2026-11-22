<?php

declare(strict_types=1);

/* 职责：运行真实多进程 Swoole 服务，任务睡眠模拟正在执行的 I/O 工作。 */
require __DIR__ . '/shutdown-bootstrap.php';

use Viswoole\Core\Server\ServerEventHook;
use Viswoole\Core\Server\Shutdown\ShutdownPolicy;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
use Viswoole\Core\Server\Shutdown\UserProcessShutdown;
use Viswoole\Core\Server\Task\TaskQueueRecoveryTimer;

$directory = $argv[1];
$duration = (float)$argv[2];
$grace = (int)$argv[3];
$userProcessMode = $argv[4] ?? '';
$GLOBALS['shutdownDirectory'] = $directory;
define('SERVER_NAME', 'stop_test');
ShutdownRuntime::prepare();
$server = new Swoole\Server('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set(ShutdownPolicy::normalizeOptions([
  'worker_num' => 1, 'task_worker_num' => 1, 'task_enable_coroutine' => true,
  'max_wait_time' => $grace, 'pid_file' => $directory . '/stop_test.pid',
  'log_file' => $directory . '/swoole.log', 'log_level' => SWOOLE_LOG_WARNING,
]));
/**
 * 构建能在停机信号后模拟异步排空的用户进程入口。
 *
 * @param string $directory 场景目录
 * @param string $name 进程标记文件前缀
 * @param bool $isStubborn 是否持续运行直至超时
 * @return Closure 用户进程入口
 */
function createUserProcessHandler(string $directory, string $name, bool $isStubborn): Closure
{
  return static function (Swoole\Process $process, ?UserProcessShutdown $receipt = null) use ($directory, $name, $isStubborn): void {
    // 单独注册信号不会维持事件循环，空闲计时器避免进程在收到停机信号前退出。
    $timer = Swoole\Timer::tick(1000, static function (): void {});
    Swoole\Process::signal(SIGTERM, static function () use ($directory, $name, $isStubborn, $receipt, $timer): void {
      if ($isStubborn) return;
      Swoole\Timer::clear($timer);
      Swoole\Timer::after(100, static function () use ($directory, $name, $receipt): void {
        file_put_contents("$directory/$name-completed", '1');
        $receipt?->acknowledge();
        Swoole\Event::exit();
      });
    });
    file_put_contents("$directory/$name-started", '1');
  };
}

if ($userProcessMode !== '') {
  $handler = createUserProcessHandler($directory, 'user', $userProcessMode === 'stubborn');
  $id = $userProcessMode === 'untracked'
    ? $server->addProcess(new Swoole\Process($handler))
    : ShutdownRuntime::registerUserProcess($server, $handler);
  if ($id === false) throw new RuntimeException('测试用户进程注册失败');
}
if ($userProcessMode === 'mixed') {
  $handler = createUserProcessHandler($directory, 'untracked', true);
  if ($server->addProcess(new Swoole\Process($handler)) === false) {
    throw new RuntimeException('未登记的测试用户进程注册失败');
  }
}
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
