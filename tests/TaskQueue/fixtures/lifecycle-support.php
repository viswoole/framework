<?php

declare(strict_types=1);

/* 职责：以真实 TaskManager 启动路径和 Swoole 定时器验证容错，独立进程隔离日志替身。 */

namespace Viswoole\Log\Facade {
  /**
   * 始终失败的日志后端，用于验证生产兜底出口。
   */
  final class Log
  {
    /**
     * @return void 注入日志故障
     */
    public static function task(mixed ...$arguments): void
    {
      throw new \RuntimeException('injected log failure');
    }
  }
}

namespace Viswoole\Core\Server {
  /**
   * 固定随机边界，确保测试正延时的下界，而不是依赖随机抽中。
   * @param int $minimum 随机下界
   * @param int $maximum 随机上界
   * @return int 下界
   */
  function random_int(int $minimum, int $maximum): int
  {
    $GLOBALS['queueTimerMinimum'] = $minimum;
    if ($minimum > $maximum) throw new \ValueError('invalid timer range');
    return $minimum;
  }
}

namespace {
  require __DIR__ . '/bootstrap.php';

  /**
   * 只提供启动所需配置，仍由生产 taskQueue() 解析租约和日志。
   */
  final class LifecycleConfig extends \Viswoole\Core\Config
  {
    public function __construct() {}

    /**
     * @return mixed 测试配置
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {
      return $name === 'task.lease_seconds' ? 1 : $default;
    }
  }

  /**
   * 商店为空时模拟配置故障，提供商店时保持原驱动路径。
   */
  final class LifecycleCache extends \Viswoole\Cache\CacheManager
  {
    /**
     * @param ?\Viswoole\Cache\Contract\CacheDriverInterface $testStore 测试注入的缓存商店，为 null 时模拟商店解析故障
     */
    public function __construct(private readonly ?\Viswoole\Cache\Contract\CacheDriverInterface $testStore = null) {}

    /**
     * @return \Viswoole\Cache\Contract\CacheDriverInterface 测试商店
     */
    public function store(?string $name = null): \Viswoole\Cache\Contract\CacheDriverInterface
    {
      return $this->testStore ?? throw new \RuntimeException('injected store failure');
    }
  }

  /**
   * 不连接 Redis，模拟恢复扫描入口故障。
   */
  final class LifecycleBrokenRedis extends \Viswoole\Cache\Driver\Redis
  {
    /**
     * @return array|false 始终抛出扫描错误
     */
    public function getTags(): array|false
    {
      throw new \RuntimeException('injected scan failure');
    }
  }

  /**
   * 运行真实注册的 workerStart 回调，不启动监听服务。
   * @param LifecycleCache $cache 商店依赖
   * @param \Swoole\Server $server 测试服务对象
   * @return void
   */
  function invokeWorkerStart(LifecycleCache $cache, \Swoole\Server $server): void
  {
    new \Viswoole\Core\Server\TaskManager($cache, new LifecycleConfig());
    $hooks = (new \ReflectionProperty(\Viswoole\Core\Server\ServerEventHook::class, 'handles'))->getValue();
    $callback = end($hooks['workerstart']);
    $callback($server, 0);
  }

}
