<?php

declare(strict_types=1);

/* 职责：隔离命令输出和应用容器，停止算法、配置合并及 Swoole 生命周期仍使用生产实现。 */

namespace Viswoole\Core\Console {
  final class Output
  {
    public const array LABEL_COLOR = ['SUCCESS' => '', 'WARNING' => '', 'ERROR' => '', 'DEBUG' => ''];

    /**
     * @return void 捕获真实 Action 的消息分支
     */
    public static function system(string $message, string $color = 'SUCCESS', int $backtrace = 0): void
    {
      $GLOBALS['shutdownMessages'][] = ['message' => $message, 'level' => $color];
    }
  }
}

namespace Viswoole\Core {
  /**
   * 只替代重启的后半步，旧服务停止仍调用真实 Action 和 Swoole。
   */
  final class App
  {
    /**
     * @return self 测试入口
     */
    public static function factory(): self { return new self(); }

    /**
     * @return object 捕获新服务启动请求
     */
    public function make(string $service, array $arguments = []): object
    {
      return new class {
        /**
         * @return void 标记仅在旧服务停止后启动
         */
        public function start(bool $daemonize = false): void { $GLOBALS['shutdownRestarted'] = true; }
      };
    }
  }
}

namespace Viswoole\Core\Facade {
  final class Event
  {
    /**
     * @return void 测试不触发应用容器内的其他业务监听
     */
    public static function emit(mixed ...$arguments): void {}
  }
}

namespace {
  require __DIR__ . '/bootstrap.php';

  /**
   * @return mixed 用配置数据替代 App 单例，不替换停止策略
   */
  function config(?string $name = null, mixed $default = null): mixed
  {
    return $GLOBALS['shutdownConfig'][$name] ?? $default;
  }

  /**
   * @return void 仅替代容器绑定；配置解析与原生 Swoole 设置保持生产实现
   */
  function bind(string $abstract, object|string $concrete): void {}

  /**
   * @return mixed 保持框架私有静态事件回调可调用，与容器反射调用一致
   */
  function invoke(callable|array $handler, array $arguments = []): mixed
  {
    if (is_array($handler) && is_string($handler[0])) {
      return (new ReflectionMethod($handler[0], $handler[1]))->invokeArgs(null, $arguments);
    }
    return $handler(...$arguments);
  }

  /**
   * @return void 保存原生 onShutdown 提示，避免子进程 STDOUT 干扰测试 JSON
   */
  function echo_log(string $message, mixed ...$arguments): void
  {
    if (isset($GLOBALS['shutdownDirectory'])) {
      file_put_contents($GLOBALS['shutdownDirectory'] . '/messages.log', $message . "\n", FILE_APPEND | LOCK_EX);
    }
  }
}
