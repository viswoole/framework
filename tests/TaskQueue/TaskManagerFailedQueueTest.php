<?php
/**
 * 职责：验证失败队列入口的存储类型边界；不连接 Redis 或注册服务事件。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Cache\Contract\CacheDriverInterface;
use Viswoole\Cache\Contract\QueueStoreInterface;
use Viswoole\Core\Server\Task\TaskManager;
use Viswoole\Core\Server\Task\TaskQueue;

/**
 * 失败队列入口必须使用已校验的同一存储实例。
 */
final class TaskManagerFailedQueueTest extends TestCase
{
  /**
   * 合法驱动交给失败队列读取，同时保留队列初始化校验。
   *
   * @return void
   */
  public function testUsesQueueStoreForFailedRecords(): void
  {
    $store = $this->createMock(QueueStoreInterface::class);
    $store->method('supportsQueueOperations')->willReturn(true);
    $store->method('supportsAtomicOperations')->willReturn(true);
    $record = ['queueState' => 'failed', 'queueId' => 'failed-task'];
    $store->expects(self::once())->method('get')->with('failed-task')->willReturn($record);
    $manager = $this->getMockBuilder(TaskManager::class)->disableOriginalConstructor()
      ->onlyMethods(['queueStore', 'taskQueue'])->getMock();
    $manager->expects(self::once())->method('queueStore')->willReturn($store);
    $manager->expects(self::once())->method('taskQueue')->willReturn(
      new TaskQueue($store)
    );

    self::assertSame($record, $manager->failedQueue()->get('failed-task'));
  }

  /**
   * 普通缓存驱动在创建失败队列前得到明确错误。
   *
   * @return void
   */
  public function testRejectsStoreWithoutQueueInterface(): void
  {
    $manager = $this->getMockBuilder(TaskManager::class)->disableOriginalConstructor()
      ->onlyMethods(['queueStore', 'taskQueue'])->getMock();
    $manager->expects(self::once())->method('queueStore')->willReturn(
      $this->createMock(CacheDriverInterface::class)
    );
    $manager->expects(self::never())->method('taskQueue');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('失败任务队列要求缓存驱动实现 QueueStoreInterface');
    $manager->failedQueue();
  }
}
