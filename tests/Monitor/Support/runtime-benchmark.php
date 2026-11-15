<?php

declare(strict_types=1);

/* 职责：在独立进程中对照固定阈值与运行时策略；不访问网络或业务存储。 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\MonitorRuntime;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\TraceContext;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\RouteIdentity;

$source = getenv('BENCH_SOURCE_PATH');
if ($source) {
  spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'Viswoole\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $file = $source . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require $file; }
  }, true, true);
}

/**
 * 创建可对照的动态环境
 * @param string $mode 测试模式
 * @param MonitorOptions $options 相同采集项
 * @return array{?MonitorRuntime,?RouteIdentity} 管理器及目标
 */
function configureRuntime(string $mode, MonitorOptions $options): array
{
  if (str_starts_with($mode, 'baseline')) { return [null, null]; }
  $catalog = new InterfaceCatalog();
  $route = new RouteIdentity('GET', '/orders/{id}', ['*']);
  $catalog->register('orders', ['template' => $route->template, 'methods' => ['GET'], 'domains' => ['*']]);
  $targets = [];
  if ($mode === 'spread') {
    for ($index = 0; $index < 256; $index++) {
      $target = new RouteIdentity('GET', '/other' . $index . '/{id}', ['*']);
      $catalog->register('other' . $index, ['template' => $target->template, 'methods' => ['GET'], 'domains' => ['*']]);
      $targets[] = $target;
    }
  }
  $catalog->complete();
  $runtime = new MonitorRuntime($options, $catalog);
  $count = match ($mode) { 'few' => 4, 'full', 'spread', 'updates', 'big' => 256, default => 0 };
  for ($index = 0; $index < $count; $index++) { $runtime->setOverride('r' . $index, $targets[$index] ?? $route, $mode === 'big' ? 0 : 100); }
  return [$runtime, $route];
}

/**
 * 测量一次有界请求生命周期
 * @param MonitorOptions $options 采集项
 * @param array{?MonitorRuntime,?RouteIdentity} $environment 动态环境
 * @param bool $isBig 是否使用大响应
 * @return void
 */
function captureRequest(MonitorOptions $options, array $environment, bool $isBig): void
{
  [$runtime, $route] = $environment;
  $trace = new RequestTrace($options, ['method' => 'GET', 'path' => '/orders/123', 'query' => ['token' => 'private']]);
  if ($runtime !== null) { $trace->attachRuntime($runtime); $trace->freezeCapturePolicy($route); }
  $trace->updateRoute(['id' => 'orders', 'paths' => ['/orders/{id}'], 'parameters' => ['id' => '123'], 'handler' => 'Orders::detail']);
  TraceContext::attach($trace);
  for ($index = 0; $index < 3; $index++) {
    TraceContext::measure(['type' => 'database', 'sql' => 'SELECT 1'], static fn (): bool => true);
  }
  if ($isBig) { $trace->appendResponse(str_repeat('x', 8192), true); }
  $trace->snapshot($trace->startedAt + 1_000_000);
  $trace->close();
  TraceContext::clear();
}

$mode = $argv[1] ?? 'empty';
$isBig = $mode === 'big' || $mode === 'baseline-big';
$options = new MonitorOptions(['enabled' => true, 'threshold_ms' => $isBig ? 0 : 1000]);
$environment = configureRuntime($mode, $options);
for ($index = 0; $index < 300; $index++) { captureRequest($options, $environment, $isBig); }
$updater = null;
if ($mode === 'updates') {
  $updater = new \Swoole\Process(static function (\Swoole\Process $process) use ($environment): void {
    for ($index = 0; $index < 100; $index++) { $environment[0]->setThresholdMs(1000 + $index); usleep(5000); }
    $process->exit(0);
  }, false, 0, false);
  if ($updater->start() === false) { throw new RuntimeException('性能测试更新进程启动失败'); }
}
$iterations = $isBig ? 1000 : 10000;
$times = [];
$usageBefore = getrusage();
$startedAt = hrtime(true);
for ($index = 0; $index < $iterations; $index++) {
  $before = hrtime(true);
  captureRequest($options, $environment, $isBig);
  $times[] = (hrtime(true) - $before) / 1000;
}
$seconds = (hrtime(true) - $startedAt) / 1e9;
$usageAfter = getrusage();
sort($times);
if ($updater !== null) {
  $status = \Swoole\Process::wait(true);
  if ($status === false || $status['code'] !== 0 || $status['signal'] !== 0) {
    throw new RuntimeException('性能测试更新进程异常退出');
  }
}
$cpu = static fn (array $usage): float => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
  + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
echo json_encode(['mode' => $mode, 'requests' => $iterations, 'rps' => round($iterations / $seconds),
  'p95_us' => round($times[(int)floor($iterations * .95) - 1], 2), 'p99_us' => round($times[(int)floor($iterations * .99) - 1], 2),
  'cpu_s' => round($cpu($usageAfter) - $cpu($usageBefore), 4),
  'php_peak_bytes' => memory_get_peak_usage(true), 'failures' => 0], JSON_THROW_ON_ERROR) . PHP_EOL;
