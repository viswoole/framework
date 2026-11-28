<?php
/**
 * 职责：验证节点并发预算配置的数字归一化与范围校验；不连接 Redis。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Swoole\Server as SwooleServer;
use Viswoole\Core\Config;
use Viswoole\Core\Server\Task\TaskManager;
use Viswoole\Tests\TaskQueue\Support\ServerStub;

require_once __DIR__ . '/Support/ServerStub.php';

/**
 * 暴露容量计算入口并绕过服务事件注册。
 */
final class ExposedCapacityManager extends TaskManager
{
  /**
   * 绕过父类构造，避免向 ServerEventHook 注册全局事件。
   * @param Config $config 配置读取入口
   */
  public function __construct(Config $config)
  {
    $this->config = $config;
    $this->instanceId = 'test-instance';
  }

  /**
   * 公开节点持久化任务预算计算。
   * @param SwooleServer $server 实际运行配置
   * @return int 本机队列任务并发上限
   */
  public function capacity(SwooleServer $server): int
  {
    // queueCapacity 为 trait 私有方法，测试经反射调用，不放宽生产可见性。
    $method = new \ReflectionMethod(TaskManager::class, 'queueCapacity');
    return $method->invoke($this, $server);
  }
}

/**
 * 并发预算配置读取回归测试。
 */
final class CapacityConfigTest extends TestCase
{
  /**
   * 构建按预设值返回并发预算的配置模拟。
   * @param mixed $value 配置值
   * @return Config 配置模拟
   */
  private function mockConfig(mixed $value): Config
  {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(
      static fn (?string $name, mixed $default = null): mixed => $name === 'task.concurrency_per_worker' ? $value : $default
    );
    return $config;
  }

  /**
   * 构造共享服务桩并按用例覆盖运行配置。
   * @param bool $isCoroutine 是否协程任务
   * @return SwooleServer 服务桩
   */
  private function mockServer(bool $isCoroutine = true): SwooleServer
  {
    return ServerStub::get(0, ['task_worker_num' => 2, 'task_enable_coroutine' => $isCoroutine]);
  }

  /**
   * 整数配置按协程预算乘以 Task Worker 数。
   */
  public function testIntegerConfigScalesWithCoroutineWorkers(): void
  {
    $manager = new ExposedCapacityManager($this->mockConfig(8));
    self::assertSame(16, $manager->capacity($this->mockServer()));
    self::assertSame(2, $manager->capacity($this->mockServer(false)));
  }

  /**
   * 环境变量等来源的数字字符串应归一化为整数生效。
   */
  public function testNumericStringConfigIsNormalized(): void
  {
    $manager = new ExposedCapacityManager($this->mockConfig('16'));
    self::assertSame(32, $manager->capacity($this->mockServer()));
  }

  /**
   * 超出范围或非数字的配置必须在计算预算时明确拒绝。
   */
  public function testInvalidConfigIsRejected(): void
  {
    $server = $this->mockServer();
    foreach ([0, 1025, 'abc', null] as $invalid) {
      $manager = new ExposedCapacityManager($this->mockConfig($invalid));
      try {
        $manager->capacity($server);
        self::fail('配置值 ' . var_export($invalid, true) . ' 未被拒绝');
      } catch (InvalidArgumentException $exception) {
        self::assertSame('任务协程预算必须为1到1024的整数', $exception->getMessage());
      }
    }
  }
}
