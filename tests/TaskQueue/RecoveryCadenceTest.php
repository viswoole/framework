<?php

declare(strict_types=1);

/* 职责：用可控时钟验证真实领取入口的节流、重入和停机边界，不以墙钟性能作为断言。 */

namespace Viswoole\Tests\TaskQueue;

use PHPUnit\Framework\TestCase;
use Viswoole\Tests\TaskQueue\Support\ScenarioProcess;

require_once __DIR__ . '/Support/ScenarioProcess.php';

/**
 * 队列领取节拍回归
 */
final class RecoveryCadenceTest extends TestCase
{
  /**
   * 活跃队列不能在定时器之外再次等待
   *
   * 第一轮消耗10ms，第二个100ms定时节拍仍须执行，不能被轮末门限跳过。
   *
   * @return void 连续两个节拍均推进领取
   */
  public function testActiveQueueRunsAtNextTimerTick(): void
  {
    self::assertSame(2, $this->scenario('active')['calls']);
  }

  /**
   * 在途任务继续使用活跃节拍
   *
   * 本轮没有新增投递但仍占用容量时，不应退为空闲等待。
   *
   * @return void 两次轮次均检查容量
   */
  public function testAssignedWorkKeepsActiveCadence(): void
  {
    self::assertSame(2, $this->scenario('assigned')['calls']);
  }

  /**
   * 空闲队列保留一秒兜底
   *
   * 修正活跃节拍不能让无任务节点每100ms访问存储。
   *
   * @return void 提前节拍被跳过，到期后重新检查
   */
  public function testIdleQueueStillThrottlesScans(): void
  {
    self::assertSame([1, 1, 2], $this->scenario('idle')['calls']);
  }

  /**
   * 轮次重入和停机均禁止再次领取
   *
   * 领取协程未返回时模拟下一拍，之后进入共享停机状态。
   *
   * @return void 两种出口均未产生额外轮次
   */
  public function testReentryAndStoppingDoNotClaimAgain(): void
  {
    self::assertSame(1, $this->scenario('guard')['calls']);
  }

  /**
   * 在独立进程执行可控时钟场景
   *
   * 假时钟和队列只影响子进程，生产领取入口仍加载真实框架trait。
   *
   * @param string $name 场景名称
   * @return array<string,mixed> 子进程观察结果
   */
  private function scenario(string $name): array
  {
    $output = tmpfile();
    $previous = getenv('QUEUE_CADENCE_SCENARIO');
    putenv('QUEUE_CADENCE_SCENARIO=' . $name);
    $process = null;
    try {
      $process = new ScenarioProcess(__DIR__ . '/fixtures/dispatch-cadence.php', [
        0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output,
      ]);
      self::assertSame(0, $process->awaitExit(5, $name));
      rewind($output);
      return json_decode(stream_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
    } finally {
      $process?->close();
      fclose($output);
      putenv($previous === false ? 'QUEUE_CADENCE_SCENARIO' : 'QUEUE_CADENCE_SCENARIO=' . $previous);
    }
  }
}
