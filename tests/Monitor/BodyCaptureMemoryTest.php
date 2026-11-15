<?php

declare(strict_types=1);

/* 职责：验证默认省略及关闭采集不额外复制超限分段，不依赖外部服务。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\ResponseCapture;
use Viswoole\Monitor\SnapshotBudget;

/** 正文采集的大分段内存回归测试。 */
final class BodyCaptureMemoryTest extends TestCase
{
  /**
   * 默认省略不复制大分段
   *
   * 在测量前分配原始片段，峰值增量仅衡量监控采集产生的额外内存。
   *
   * @return void 断言完成
   */
  public function testUnconfiguredHandlerDoesNotCopyOversizedChunk(): void
  {
    $this->assertOversizedChunkIsNotCopied(new MonitorOptions(['max_body_bytes' => 8192]));
  }

  /**
   * 关闭采集不复制大分段
   *
   * 即使配置处理器，未采集正文也不应为不可调用的处理器构造输入。
   *
   * @return void 断言完成
   */
  public function testRedactedBodyDoesNotCopyOversizedChunk(): void
  {
    $options = new MonitorOptions(['max_body_bytes' => 8192, 'privacy' => PrivacyOptions::disableBodies(),
      'body_truncator' => static function (): array { self::fail('关闭采集不能调用处理器'); }]);
    $this->assertOversizedChunkIsNotCopied($options, '[CAPTURE_DISABLED]');
  }

  /**
   * 启用处理器时单分段内存仍有界
   *
   * 超限分段允许复制一次拼接前缀传给处理器（文档化设计），但超限后的
   * 迟到片段不得再触发任何拼接、复制或处理器调用。
   *
   * @return void 断言完成
   */
  public function testEnabledHandlerKeepsMemoryBounded(): void
  {
    $seen = [];
    $options = new MonitorOptions(['max_body_bytes' => 40, 'body_truncator' =>
      static function (string $body, array $context) use (&$seen): array {
        $seen[] = [$body, $context['is_complete'], $context['max_bytes']];
        return ['code' => 0];
      }]);
    $capture = new ResponseCapture($options, new SnapshotBudget($options->maxSnapshotBytes), new PayloadSanitizer($options));
    $capture->updateResponse(['content_type' => 'application/json']);
    $capture->appendResponse('{"code":200,');
    $chunk = str_repeat('x', 16 * 1024 * 1024);
    $late = str_repeat('y', 16 * 1024 * 1024);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $capture->appendResponse($chunk, true);
    // 单次超限最多复制一份分段原文（拼接 candidate），不允许出现双份累积。
    self::assertLessThan(2 * (16 * 1024 * 1024), memory_get_peak_usage() - $before);
    // 超限后的迟到片段仅累计字节数，不得再为处理器构造输入。
    $capture->appendResponse($late);
    self::assertCount(1, $seen);
    self::assertSame('{"code":200,' . $chunk, $seen[0][0]);
    self::assertTrue($seen[0][1]);
    self::assertSame($options->bodyLimit(), $seen[0][2]);
    $body = $capture->snapshot()['body'];
    self::assertSame(['code' => 0], $body['content']);
    // bytes 为实际写出总量，包含超限后迟到片段的累计
    self::assertSame(strlen('{"code":200,') + strlen($chunk) + strlen($late), $body['bytes']);
    self::assertTrue($body['truncated']);
  }

  /**
   * 测量采集附加内存
   *
   * 允许小额分配用于占位与元信息，禁止复制整个 16 MiB 输入片段。
   *
   * @param MonitorOptions $options 当前采集策略
   * @param string $expectedContent 当前场景应输出的占位
   * @return void 断言完成
   */
  private function assertOversizedChunkIsNotCopied(MonitorOptions $options, string $expectedContent = '[BODY_LIMIT]'): void
  {
    $capture = new ResponseCapture($options, new SnapshotBudget($options->maxSnapshotBytes), new PayloadSanitizer($options));
    $capture->updateResponse(['content_type' => 'application/json']);
    $capture->appendResponse(str_repeat('x', 1000));
    $chunk = str_repeat('x', 16 * 1024 * 1024);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $capture->appendResponse($chunk, true);
    $extraBytes = memory_get_peak_usage() - $before;
    self::assertLessThan(1024 * 1024, $extraBytes);
    $snapshot = $capture->snapshot();
    self::assertTrue($snapshot['completed']);
    self::assertSame(1000 + strlen($chunk), $snapshot['body']['bytes']);
    self::assertSame($expectedContent, $snapshot['body']['content']);
    self::assertSame($expectedContent === '[BODY_LIMIT]', $snapshot['body']['truncated']);
  }
}
