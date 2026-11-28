<?php

declare(strict_types=1);

/* 职责：验证真实 Worker 启动和恢复扫描的日志故障隔离。 */
require __DIR__ . '/lifecycle-support.php';

set_error_handler(static function (int $severity, string $message): never {
  throw new \ErrorException($message, severity: $severity);
});
$logPath = tempnam(sys_get_temp_dir(), 'queue-lifecycle-');
$previousLog = ini_set('error_log', $logPath);
$server = new \Swoole\Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set(['task_worker_num' => 1]);
$results = [];
try {
  invokeWorkerStart(new LifecycleCache(), $server);
  $results['startupReturned'] = true;
  invokeWorkerStart(new LifecycleCache(new LifecycleBrokenRedis()), $server);
  $results['recoveryScheduled'] = (\Swoole\Timer::stats()['num'] ?? 0) > 0;
  \Swoole\Timer::clearAll();

} finally {
  \Swoole\Timer::clearAll();
  ini_set('error_log', $previousLog);
  restore_error_handler();
}
$logs = file_get_contents($logPath);
unlink($logPath);
$results['loggedStoreError'] = str_contains($logs, 'injected store failure');
$results['loggedScanError'] = str_contains($logs, 'injected scan failure');
$results['loggedBackendError'] = str_contains($logs, 'injected log failure');
echo json_encode($results, JSON_THROW_ON_ERROR);
