<?php

declare(strict_types=1);

/* 职责：断言前收集真实长任务、Swoole 超时及框架强停的退出观测。 */
require __DIR__ . '/shutdown-bootstrap.php';

use Viswoole\Core\Server\Action;
use Viswoole\Core\Server\Shutdown\ServerProcessTree;
use Viswoole\Core\Server\Shutdown\ShutdownPolicy;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;
use Viswoole\Core\Server\Shutdown\StopResult;

/**
 * 等待任务已进入处理器，避免停止时序碰巧提前
 * @param string $directory 场景路径
 * @return void
 */
function awaitTaskStart(string $directory): void
{
  $deadline = microtime(true) + 5;
  while (!is_file($directory . '/task-started') || !is_file($directory . '/stop_test.pid')) {
    if (microtime(true) > $deadline) throw new RuntimeException('等待测试服务启动超时：' . $directory);
    usleep(10000);
  }
}

/**
 * 启动独立服务后调用真实 Action::close，终止或清理只作用于本场景的进程树。
 * @param array $scenario 任务时长、退出窗口及是否模拟僵死服务
 * @return array 停止结果和实际进程状态
 */
function observeStop(array $scenario): array
{
  $directory = sys_get_temp_dir() . '/viswoole-stop-' . bin2hex(random_bytes(6));
  mkdir($directory);
  $fixture = ($scenario['legacy'] ?? false) ? 'viswoole-legacy-server.php' : 'viswoole-shutdown-server.php';
  if ($scenario['stubborn']) $fixture = 'viswoole-stubborn-server.php';
  $command = [PHP_BINARY, __DIR__ . '/' . $fixture, $directory, (string)$scenario['duration'], (string)$scenario['grace']];
  $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/stdout.log', 'w'],
    2 => ['file', $directory . '/stderr.log', 'w']], $pipes);
  if (!is_resource($process)) throw new RuntimeException('创建测试服务失败');
  $tree = null;
  try {
    awaitTaskStart($directory);
    $pid = (int)file_get_contents($directory . '/stop_test.pid');
    $tree = new ServerProcessTree($pid);
    // 当前配置故意比运行服务的窗口短，验证停止采用实际启动回执，而不是部署后新配置。
    $GLOBALS['shutdownConfig'] = ['server.options' => ['max_wait_time' => 1],
      'server.servers.stop_test.options.pid_file' => $directory . '/stop_test.pid'];
    $GLOBALS['shutdownMessages'] = [];
    $GLOBALS['shutdownRestarted'] = false;
    $started = hrtime(true);
    if ($scenario['restart'] ?? false) Action::start('stop_test', true);
    else Action::close('stop_test');
    return stopObservations($directory, $tree, (hrtime(true) - $started) / 1e9);
  } finally {
    if ($tree !== null && $tree->isAlive()) $tree->forceKill();
    if ($tree === null) proc_terminate($process, SIGKILL);
    proc_close($process);
  }
}

/**
 * 收集停止结果和实际进程状态的观测值
 * @param string $directory 场景路径
 * @param ServerProcessTree $tree 进程归属
 * @param float $elapsed 等待时长
 * @return array 观测值
 */
function stopObservations(string $directory, ServerProcessTree $tree, float $elapsed): array
{
  $path = $directory . '/stop_test.pid.shutdown.json';
  $record = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
  return ['elapsed' => $elapsed, 'restarted' => $GLOBALS['shutdownRestarted'], 'completed' => is_file($directory . '/task-completed'),
    'status' => $record['status'] ?? null, 'activeTasks' => $record['active_tasks'] ?? null,
    'messages' => $GLOBALS['shutdownMessages'], 'hasAliveProcesses' => $tree->isAlive(),
    'hasPidFile' => is_file($directory . '/stop_test.pid'),
    'nativeMessages' => is_file($directory . '/messages.log') ? file_get_contents($directory . '/messages.log') : '',
    'logs' => is_file($directory . '/swoole.log') ? file_get_contents($directory . '/swoole.log') : ''];
}

/**
 * @return array 共享计数必须保留返回值，并在请求回调抛异常时归零
 */
function observeCallbackCounters(): array
{
  ShutdownRuntime::prepare();
  $tasks = (new ReflectionProperty(ShutdownRuntime::class, 'activeTasks'))->getValue();
  $requests = (new ReflectionProperty(ShutdownRuntime::class, 'activeRequests'))->getValue();
  $taskDuring = 0;
  $taskResult = ShutdownRuntime::runTask(static function () use ($tasks, &$taskDuring): array {
    $taskDuring = $tasks->get();
    return ['ok' => true];
  }, []);
  $requestDuring = 0;
  $hasThrown = false;
  try {
    ShutdownRuntime::runRequest(static function () use ($requests, &$requestDuring): never {
      $requestDuring = $requests->get();
      throw new RuntimeException('request failed');
    }, []);
  } catch (RuntimeException) {
    $hasThrown = true;
  }
  return ['taskDuring' => $taskDuring, 'taskAfter' => $tasks->get(), 'taskResult' => $taskResult,
    'requestDuring' => $requestDuring, 'requestAfter' => $requests->get(), 'requestThrew' => $hasThrown];
}

$options = ShutdownPolicy::normalizeOptions(['max_wait_time' => 12]);
$rejected = 0;
foreach ([['max_wait_time' => 0], ['max_wait_time' => -1], ['reload_async' => false], ['max_wait_time' => 'invalid']] as $invalid) {
  try {
    ShutdownPolicy::normalizeOptions($invalid);
  } catch (Viswoole\Core\Exception\ServerException) {
    $rejected++;
  }
}
$results = ['counters' => observeCallbackCounters(), 'policy' => ['defaults' => ShutdownPolicy::normalizeOptions([]), 'options' => $options,
  'waitSeconds' => ShutdownPolicy::fromOptions($options)->waitSeconds(), 'rejected' => $rejected],
  'graceful' => observeStop(['duration' => 11, 'grace' => 15, 'stubborn' => false]),
  'restart' => observeStop(['duration' => 6, 'grace' => 10, 'stubborn' => false, 'restart' => true]),
  'swooleTimeout' => observeStop(['duration' => 20, 'grace' => 1, 'stubborn' => false]),
  'frameworkTimeout' => observeStop(['duration' => 0, 'grace' => 1, 'stubborn' => true]),
  'legacy' => observeStop(['duration' => 0, 'grace' => 1, 'stubborn' => false, 'legacy' => true]),
  'unconfirmedMessage' => StopResult::Unconfirmed->message('legacy')];
echo json_encode($results, JSON_THROW_ON_ERROR);
