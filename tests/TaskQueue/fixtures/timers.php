<?php

declare(strict_types=1);

/* 职责：在独立 CLI reactor 中验证 Swoole 错峰与周期定时器，避免未启动 Server 的全局状态干扰。 */
require __DIR__ . '/lifecycle-support.php';

set_error_handler(static function (int $severity, string $message): never {
  throw new ErrorException($message, severity: $severity);
});
$logPath = tempnam(sys_get_temp_dir(), 'queue-timer-');
$previousLog = ini_set('error_log', $logPath);
$results = [];
try {
  Viswoole\Core\Server\Task\TaskQueueRecoveryTimer::start(0, static function (): void {});
  $results['invalidTimerContained'] = true;
  $calls = 0;
  Viswoole\Core\Server\Task\TaskQueueRecoveryTimer::start(10, static function () use (&$calls): void {
    $calls++;
    Swoole\Timer::clearAll();
  });
  Swoole\Event::wait();
  $results['timerCalls'] = $calls;
  $results['minimumDelay'] = $GLOBALS['queueTimerMinimum'];
} finally {
  Swoole\Timer::clearAll();
  ini_set('error_log', $previousLog);
  restore_error_handler();
}
$logs = file_get_contents($logPath);
unlink($logPath);
$results['loggedTimerError'] = str_contains($logs, '任务队列错峰扫描未启动');
echo json_encode($results, JSON_THROW_ON_ERROR);
