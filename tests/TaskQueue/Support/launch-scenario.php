<?php
/**
 * 职责：在独立会话内启动场景并回传进程组标识；不执行业务断言。
 */
declare(strict_types=1);

if (!function_exists('posix_setsid') || posix_setsid() !== getmypid()) {
    fwrite(STDERR, "无法创建独立测试进程组，需要 POSIX 扩展\n");
    exit(1);
}
$ready = fopen('php://fd/3', 'w');
if ($ready === false) throw new RuntimeException('无法打开场景启动握手通道');
fwrite($ready, (string)getmypid() . "\n");
fclose($ready);
require $argv[1];
