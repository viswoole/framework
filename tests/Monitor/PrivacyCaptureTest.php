<?php

declare(strict_types=1);

/* 职责：验证采集关闭在读取 HTTP 正文之前生效，处理器摘要仍按正确方向脱敏。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;
use Viswoole\Monitor\Http\RequestCapture;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\RequestTrace;

/** 采集入口隐私边界契约。 */
final class PrivacyCaptureTest extends TestCase
{
  /**
   * 请求正文关闭时不读取原文
   *
   * 同时隐藏 post 副本，避免自定义凭证字段绕过正文开关。
   *
   * @return void 断言完成
   */
  public function testDisabledRequestBodyNeverReadsRawContent(): void
  {
    $options = new MonitorOptions(['privacy' => ['rules' => [[
      'paths' => ['/private'], 'methods' => ['POST'], 'request' => ['body' => ['capture' => false]],
    ]]], 'body_truncator' => static function (): array { self::fail('不得调用处理器'); }]);
    $request = $this->createMock(Request::class);
    $request->server = ['request_uri' => '/private', 'request_method' => 'POST'];
    $request->header = ['content-type' => 'application/json'];
    $request->post = ['newCredential' => 'private'];
    $request->expects(self::never())->method('rawContent');
    $snapshot = (new RequestCapture($options))->capture($request);
    self::assertSame('[CAPTURE_DISABLED]', $snapshot['body']['content']);
    self::assertFalse($snapshot['body']['size_known']);
    self::assertSame('[CAPTURE_DISABLED]', $snapshot['post']);
  }

  /**
   * 响应摘要使用响应字段规则
   *
   * 请求 code 为验证码时隐藏，响应 code 为业务码时保留，摘要内 token 仍隐藏。
   *
   * @return void 断言完成
   */
  public function testTruncatedResponseUsesResponsePrivacy(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'max_body_bytes' => 80,
      'privacy' => ['rules' => [['paths' => ['/auth'],
        'request' => ['body' => ['redact_fields' => ['code']]],
      ]]],
      'body_truncator' => static fn (): array => ['code' => 0, 'token' => 'private'],
    ]);
    $trace = new RequestTrace($options, ['path' => '/auth', 'method' => 'POST', 'post' => ['code' => '123456']]);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->appendResponse(str_repeat('x', 1000), true);
    $snapshot = $trace->finish();
    self::assertSame('[REDACTED]', $snapshot['request']['post']['code']);
    self::assertSame(['code' => 0, 'token' => '[REDACTED]'], $snapshot['response']['body']['content']);
  }
}
