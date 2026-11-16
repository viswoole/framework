<?php

declare(strict_types=1);

/* 职责：模拟会正常退出但没有新版停机回执的旧实例。 */
$directory = $argv[1];
Swoole\Process::signal(SIGINT, static function (): void {
  Swoole\Timer::clearAll();
  Swoole\Event::exit();
});
file_put_contents($directory . '/stop_test.pid', (string)getmypid());
file_put_contents($directory . '/task-started', '1');
Swoole\Timer::tick(1000, static function (): void {});
Swoole\Event::wait();
