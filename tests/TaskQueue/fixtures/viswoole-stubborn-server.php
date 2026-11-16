<?php

declare(strict_types=1);

/* 职责：模拟无法响应优雅退出的主进程与子进程，只供强停清理回归使用。 */
$directory = $argv[1];
Swoole\Process::signal(SIGINT, static function (): void {});
$child = new Swoole\Process(static function () use ($directory): void {
  Swoole\Process::signal(SIGINT, static function (): void {});
  file_put_contents($directory . '/child.pid', (string)getmypid());
  Swoole\Timer::tick(1000, static function (): void {});
  Swoole\Event::wait();
});
$child->start();
file_put_contents($directory . '/stop_test.pid', (string)getmypid());
file_put_contents($directory . '/task-started', '1');
Swoole\Timer::tick(1000, static function (): void {});
Swoole\Event::wait();
