<?php

/**
 * 职责：提供跨测试类共享的 Swoole 服务桩；不启动真实服务或事件循环。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue\Support;

use Swoole\Server as SwooleServer;

/**
 * Swoole 每个进程只允许创建一个 Server 实例，测试间共享并按需覆盖运行配置。
 */
final class ServerStub
{
  /**
   * @var SwooleServer|null 当前进程共享的服务桩
   */
  private static ?SwooleServer $instance = null;

  /**
   * 获取共享服务桩，可按用例覆盖 Worker 身份与运行配置。
   * @param int $workerId 当前 Worker 身份
   * @param array<string,mixed>|null $setting 服务运行配置，null 保持不变
   * @return SwooleServer 服务桩
   */
  public static function get(int $workerId = 0, ?array $setting = null): SwooleServer
  {
    if (self::$instance === null) {
      self::$instance = new class('127.0.0.1', 0) extends SwooleServer {
        public $worker_id = 0;
        public $setting = ['task_worker_num' => 2, 'task_enable_coroutine' => true];
      };
    }
    self::$instance->worker_id = $workerId;
    if ($setting !== null) { self::$instance->setting = $setting; }
    return self::$instance;
  }
}
