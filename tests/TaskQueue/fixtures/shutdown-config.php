<?php

declare(strict_types=1);

/* 职责：验证真实 Server 配置合并、原生选项和命令 PID 定位保持一致。 */
require __DIR__ . '/shutdown-bootstrap.php';

$directory = sys_get_temp_dir() . '/viswoole-config-' . bin2hex(random_bytes(6));
define('BASE_PATH', $directory);
$pidFile = $directory . '/custom/service.pid';
$GLOBALS['shutdownConfig'] = [
  'server.servers.stop_test' => ['type' => Swoole\Http\Server::class,
    'construct' => ['host' => '127.0.0.1', 'port' => 0],
    'options' => ['max_wait_time' => 12, 'pid_file' => $pidFile]],
  'server.options' => ['max_wait_time' => 45, 'worker_num' => 1, 'task_worker_num' => 0],
  'server.servers.stop_test.options.pid_file' => $pidFile,
];
$server = new Viswoole\Core\Server('stop_test', new Viswoole\Core\Event());
$config = $server->getConfig();
echo json_encode([
  'mergedWait' => $config['options']['max_wait_time'],
  'nativeWait' => $server->getServer()->setting['max_wait_time'],
  'reloadAsync' => $server->getServer()->setting['reload_async'],
  'pidMatches' => Viswoole\Core\Server\Action::getPidFile('stop_test') === $pidFile,
  'hasWorkerExitHook' => isset($config['events']['workerexit']),
], JSON_THROW_ON_ERROR);
