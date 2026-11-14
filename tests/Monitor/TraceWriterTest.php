<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use Viswoole\Monitor\Dto\SlowRequestSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Viswoole\Log\Drives\File;
use Viswoole\Monitor\Contract\TraceWriterInterface;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;
use Viswoole\Monitor\SlowRequestMonitor;
use Viswoole\Monitor\TraceContext;
use Viswoole\Monitor\Writer\JsonTraceWriter;

/**
 * 慢请求记录存储测试
 *
 * 验证真实 JSON 日志输出和持久化失败隔离。
 */
final class TraceWriterTest extends TestCase
{
  /**
   * JSON 写入器落盘真实日志文件，记录含级别与上下文字段
   */
  public function testJsonWriterPersistsRecord(): void
  {
    $directory = sys_get_temp_dir() . '/monitor_logs_' . bin2hex(random_bytes(6));
    $driver = new File(json_flags: JSON_INVALID_UTF8_SUBSTITUTE, log_dir: $directory);
    $writer = new JsonTraceWriter($driver);
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0,
      'request_id' => ['generator' => static fn (): string => 'test']]), []);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->appendResponse('{"ok":true}', true);
    $writer->write($trace->snapshot());
    $files = glob($directory . '/*/*/*.log');
    try {
      self::assertCount(1, $files);
      $record = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
      self::assertSame('slow_request', $record['level']);
      self::assertSame('test', $record['context']['request_id']);
    } finally {
      if ($files !== []) {
        unlink($files[0]);
        rmdir(dirname($files[0]));
        rmdir(dirname($files[0], 2));
      }
      rmdir($directory);
    }
  }

  /**
   * 存储失败发生在上下文清理之后，仅记录异常类型告警，不影响请求收尾
   */
  public function testWriterFailureIsReportedAfterContextCleanup(): void
  {
    $writer = new class () implements TraceWriterInterface {
      public function write(SlowRequestSnapshot $snapshot): void
      {
        if (TraceContext::current() !== null) { throw new RuntimeException('上下文未清理'); }
        throw new RuntimeException('storage unavailable');
      }
    };
    $warnings = [];
    set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
      $warnings[] = $errstr;
      return true;
    });
    try {
      $monitor = new SlowRequestMonitor(new MonitorOptions(['enabled' => true, 'threshold_ms' => 0]), $writer);
      $monitor->start('/', static fn (): array => []);
      $monitor->finish();
      self::assertNull(TraceContext::current());
      $reported = implode("\n", $warnings);
      self::assertStringContainsString(RuntimeException::class, $reported);
      self::assertStringNotContainsString('storage unavailable', $reported);
    } finally {
      restore_error_handler();
    }
  }
}
