<?php

declare(strict_types=1);

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;

/**
 * 请求快照采集测试
 *
 * 原始请求读取边界：保留合法零正文、只采集上传字段名。
 */
final class RequestSnapshotTest extends TestCase
{
  /**
   * 字符串 "0" 是合法 JSON 正文不得丢弃；上传文件仅采集字段名，不含路径与原名
   */
  public function testZeroBodyAndFileFieldNamesArePreserved(): void
  {
    $request = $this->createMock(Request::class);
    $request->server = ['request_method' => 'POST', 'request_uri' => '/upload'];
    $request->header = ['content-type' => 'application/json'];
    $request->files = ['photo' => ['tmp_name' => '/private/file', 'name' => 'private-name']];
    $request->method('rawContent')->willReturn('0');
    $snapshot = (new RequestCapture(new MonitorOptions()))->capture($request);
    self::assertSame(0, $snapshot['body']['content']);
    self::assertSame(1, $snapshot['body']['bytes']);
    self::assertSame(['photo'], $snapshot['files']);
    self::assertStringNotContainsString('/private/file', json_encode($snapshot));
  }

  /**
   * path_info 缺失时 request_uri 携带的 query 不参与路径匹配与采集路径
   */
  public function testExtractPathStripsQueryString(): void
  {
    $request = $this->createMock(Request::class);
    $request->server = ['request_uri' => '/health?token=private'];
    $request->header = [];
    self::assertSame('/health', RequestCapture::extractPath($request));
    self::assertSame('/health', (new RequestCapture(new MonitorOptions()))->capture($request)['path']);
  }

  /**
   * 正文上限为 0 时正文不输出，但响应状态码仍完整保留
   */
  public function testZeroBodyLimitStillPreservesResponseStatus(): void
  {
    $trace = new RequestTrace(new MonitorOptions(['threshold_ms' => 0, 'max_body_bytes' => 0]), ['path' => '/']);
    $trace->updateResponse(['status' => 503, 'content_type' => 'application/json']);
    $trace->appendResponse('unavailable', true);
    $record = $trace->finish();
    self::assertSame(503, $record['response']['status']);
    self::assertSame('[BODY_LIMIT]', $record['response']['body']['content']);
    self::assertTrue($record['response']['body']['truncated']);
  }
}
