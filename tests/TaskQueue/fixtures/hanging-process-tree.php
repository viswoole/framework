<?php
/**
 * 职责：创建只属于测试会话的挂起子进程；由场景运行器验证超时清理。
 */
declare(strict_types=1);

$pidFile = getenv('QUEUE_TEST_CHILD_PID_FILE');
if (!$pidFile) throw new RuntimeException('未指定测试子进程记录路径');
$child = proc_open([PHP_BINARY, '-r', 'sleep(30);'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!is_resource($child)) throw new RuntimeException('无法创建挂起测试子进程');
file_put_contents($pidFile, (string)proc_get_status($child)['pid']);
sleep(30);
