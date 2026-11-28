<?php

declare(strict_types=1);

/* 职责：管理真实集群测试节点的端口、配置和独立进程组，保证故障注入后无进程残留。 */

namespace Viswoole\Tests\TaskQueue\Support;

use RuntimeException;

require_once __DIR__ . '/ScenarioProcess.php';

/**
 * 单个真实 Swoole 测试节点
 */
final class ClusterNode
{
  /**
   * @var ScenarioProcess|null 已校验独立进程组的节点
   */
  private ?ScenarioProcess $process = null;
  /**
   * @var int HTTP 测试端口
   */
  private int $port;
  /**
   * @var string 临时节点配置
   */
  private string $specPath;
  /**
   * @var string 节点日志路径
   */
  private string $logPath;
  /**
   * @var resource 场景标准输出及错误
   */
  private mixed $output;

  /**
   * 启动隔离节点
   *
   * 参数通过临时 JSON 传入，原进程组启动和清理复用已验证的运行器。
   *
   * @param string $node 节点名称
   * @param string $prefix 同组节点共享的 Redis 随机前缀
   * @return void 节点进程已启动
   */
  public function __construct(string $node, string $prefix)
  {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) { throw new RuntimeException('无法分配节点测试端口'); }
    $this->port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $this->specPath = tempnam(sys_get_temp_dir(), 'cluster-node-');
    $this->logPath = $this->specPath . '.log';
    file_put_contents($this->specPath, json_encode(['node' => $node, 'prefix' => $prefix,
      'port' => $this->port, 'log' => $this->logPath], JSON_THROW_ON_ERROR));
    $this->output = tmpfile();
    $previous = getenv('QUEUE_CLUSTER_NODE_SPEC');
    putenv('QUEUE_CLUSTER_NODE_SPEC=' . $this->specPath);
    try {
      $this->process = new ScenarioProcess(
        dirname(__DIR__) . '/fixtures/cluster-node.php',
        [0 => ['file', '/dev/null', 'r'], 1 => $this->output, 2 => $this->output]
      );
    } finally {
      putenv($previous === false ? 'QUEUE_CLUSTER_NODE_SPEC' : 'QUEUE_CLUSTER_NODE_SPEC=' . $previous);
    }
  }

  /**
   * 请求当前节点
   *
   * 启动探活时允许暂时连接失败，正式响应必须是 JSON。
   *
   * @param string $path 路径及查询参数
   * @return array|null 解码响应，尚未就绪时为空
   */
  public function request(string $path): ?array
  {
    $handle = curl_init('http://127.0.0.1:' . $this->port . $path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 2500]);
    $result = curl_exec($handle);
    unset($handle);
    return is_string($result) ? json_decode($result, true, flags: JSON_THROW_ON_ERROR) : null;
  }

  /**
   * 终止整个节点进程树
   *
   * 可用于模拟节点突然退出；清理器重复调用不会重复处理旧进程身份。
   *
   * @return void 进程与文件已清理
   */
  public function close(): void
  {
    if ($this->process !== null) {
      $this->process->close();
      $this->process = null;
    }
    if (is_resource($this->output)) { fclose($this->output); }
    if (is_file($this->specPath)) { unlink($this->specPath); }
    if (is_file($this->logPath)) { unlink($this->logPath); }
  }
}
