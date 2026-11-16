<?php
/**
 * 职责：提供审查回归所需对象载荷与真实管理器入口；不替代生产状态转换。
 */
declare(strict_types=1);

use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Core\Config;
use Viswoole\Core\Server\Task\TaskManager;
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 模拟恢复对象时更新运行期状态的合法业务载荷。
 */
final class QueueWakePayload
{
  /**
   * @var int 当前对象已恢复次数
   */
  public int $wakeCount = 0;

  /**
   * 标记一次对象恢复，以检验重序列化是否污染存储比较
   * @return void
   */
  public function __wakeup(): void
  {
    $this->wakeCount++;
  }
}

/**
 * 仅提供可调整的重试配置，不加载应用配置目录。
 */
final class ReviewRetryConfig extends Config
{
  /**
   * 初始化可调整的配置替身
   * @param array $retry 重试配置
   */
  public function __construct(
    /**
     * @var array 当前重试配置
     */
    public array $retry
  ) {
  }

  /**
   * 提供重试配置，其余配置使用调用方默认值
   * @param string|null $name 配置键
   * @param mixed $default 默认值
   * @return mixed 配置值
   */
  public function get(?string $name = null, mixed $default = null): mixed
  {
    return $name === 'task.retry' ? $this->retry : $default;
  }
}

/**
 * 使用生产惰性初始化逻辑，跳过无关的服务器事件注册。
 */
final class ReviewRetryManager extends TaskManager
{
  /**
   * 注入测试商店与配置
   * @param CacheDriverInterface $store 独立测试商店
   * @param Config $config 可调整配置
   */
  public function __construct(CacheDriverInterface $store, Config $config)
  {
    $this->queueDriver = $store;
    $this->config = $config;
  }

  /**
   * 暴露生产队列初始化入口
   * @return TaskQueue 完整初始化的队列实例
   * @throws InvalidArgumentException 重试配置无效时抛出
   */
  public function resolveQueue(): TaskQueue
  {
    return $this->taskQueue();
  }
}
