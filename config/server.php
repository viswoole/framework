<?php
// +----------------------------------------------------------------------
// | swoole服务配置文件
// +----------------------------------------------------------------------

declare (strict_types=1);

use Swoole\Constant;
use Swoole\Http\Server as httpServer;
use Swoole\Server as SwooleServer;
use Viswoole\Core\Console\Output;
use Viswoole\HttpServer\EventHandle as HttpEventHandle;
use Viswoole\HttpServer\Exception\Handle as HttpExceptionHandle;

return [
  // 默认启动的服务
  'default_start_server' => env('default_start_server', 'http'),
  // 服务定义
  'servers' => [
    'http' => [
      // 服务类型
      'type' => httpServer::class,
      // 服务异常处理类
      'exception_handle' => HttpExceptionHandle::class,
      // 构造参数 参考https://wiki.swoole.com/#/server/methods?id=__construct
      'construct' => [
        // 指定监听的 ip 地址。
        'host' => '0,0,0,0',
        // 指定监听的端口
        'port' => 9501,
        // 运行模式
        'mode' => SWOOLE_PROCESS,
        // Server 的类型
        'sock_type' => SWOOLE_SOCK_TCP,
      ],
      'options' => [
        // 上传文件最大尺寸 单位mb
        Constant::OPTION_UPLOAD_MAX_FILESIZE => 5 * 1024,
        // 启用HTTP2协议解析
        Constant::OPTION_OPEN_HTTP2_PROTOCOL => true,
        // 如果需要ssl访问则需要配置 Constant::OPTION_SSL_CERT_FILE 和 Constant::OPTION_SSL_KEY_FILE
        // 进程守护运行
        Constant::OPTION_DAEMONIZE => false,
        // 任务进程数量 最大值不得超过 swoole_cpu_num() * 1000  0代表不开启
        Constant::OPTION_TASK_WORKER_NUM => swoole_cpu_num(),
        // 任务协程
        Constant::OPTION_TASK_ENABLE_COROUTINE => true
      ],
      'events' => [
        // HTTP请求处理
        Constant::EVENT_REQUEST => [HttpEventHandle::class, 'onRequest']
      ]
    ]
  ],
  // 全局配置
  'options' => [
    // 一键协程化Hook函数范围 参考https://wiki.swoole.com/#/server/setting?id=hook_flags
    Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL,
    // 是否启用异步风格服务器的协程支持
    Constant::OPTION_ENABLE_COROUTINE => true,
    // 最大协程数
    Constant::OPTION_MAX_CONCURRENCY => 100000,
    // 进程守护运行
    Constant::OPTION_DAEMONIZE => false,
    // 进程守护运行默认输出日志路径
    Constant::OPTION_LOG_FILE => BASE_PATH . '/runtime/sysLog.log',
    // 工作进程数量
    Constant::OPTION_WORKER_NUM => swoole_cpu_num(),
    // 任务进程数量 最大值不得超过 swoole_cpu_num() * 1000  0代表不开启
    Constant::OPTION_TASK_WORKER_NUM => 0,
    // 最大请求数 0为不限制
    Constant::OPTION_MAX_REQUEST => 100000,
    // 客户端连接的缓存区长度
    Constant::OPTION_SOCKET_BUFFER_SIZE => 2 * 1024 * 1024,
    // 发送输出缓冲区内存尺寸
    Constant::OPTION_BUFFER_OUTPUT_SIZE => 2 * 1024 * 1024,
    // 数据包最大尺寸 最小64k
    Constant::OPTION_PACKAGE_MAX_LENGTH => 2 * 1024 * 1024,
    // 日志输出等级
    Constant::OPTION_LOG_LEVEL => SWOOLE_LOG_WARNING
  ],
  // 全局EVENTS
  'events' => [
    Constant::EVENT_START => function (SwooleServer $server): void {
      $serverName = SERVER_NAME;
      Output::echo("$serverName 服务启动 进程PID:" . $server->master_pid, 'NOTICE', backtrace: 0);
    },
    Constant::EVENT_SHUTDOWN => function (): void {
      $serverName = SERVER_NAME;
      Output::echo("$serverName 服务已经安全关闭", 'NOTICE', backtrace: 0);
    }
  ]
];
