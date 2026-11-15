<?php

declare(strict_types=1);

/* 职责：验证独立正文调用继承已解析的方向和方法，显式覆盖不污染原实例。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\Privacy\Target;

/** 正文作用域上下文回归契约。 */
final class BodyScopeContextTest extends TestCase
{
  /**
   * 默认方向不能绕过请求采集开关
   *
   * 独立调用不重复传入 context，也必须遵循已解析的请求正文策略。
   *
   * @return void 断言完成
   */
  public function testRequestScopeKeepsCaptureDisabledWithoutContext(): void
  {
    $options = new MonitorOptions(['privacy' => ['defaults' => ['request' => ['body' => ['capture' => false]]]],
      'body_truncator' => static function (): array { self::fail('关闭采集不得调用处理器'); }]);
    $scope = (new PayloadSanitizer($options, '/private'))->forScope(Target::REQUEST_BODY, 'POST');
    self::assertFalse($scope->canCapture());
    $body = $scope->sanitizeBody('{"credential":"private-value"}', 'application/json');
    self::assertSame('[CAPTURE_DISABLED]', $body['content']);
    self::assertFalse($body['truncated']);
    $limited = $scope->truncateBody(str_repeat('x', 20000), ['content_type' => 'application/json', 'is_complete' => true]);
    self::assertSame('[CAPTURE_DISABLED]', $limited['content']);
    self::assertFalse($limited['truncated']);
  }

  /**
   * 默认方向沿用请求字段规则
   *
   * 请求验证码规则不会在独立调用时被替换为响应业务码规则。
   *
   * @return void 断言完成
   */
  public function testRequestFieldsRemainScopedWithoutContext(): void
  {
    $options = new MonitorOptions(['privacy' => ['defaults' => ['request' => ['body' => ['redact_fields' => ['code']]]]]]);
    $scope = (new PayloadSanitizer($options))->forScope(Target::REQUEST_BODY, 'POST');
    self::assertSame('[REDACTED]', $scope->sanitizeBody('{"code":"123456"}', 'application/json')['content']['code']);
    self::assertSame(0, $scope->forScope(Target::RESPONSE_BODY)->sanitizeBody('{"code":0}', 'application/json')['content']['code']);
  }

  /**
   * 处理器继承方向及已知方法
   *
   * 分别覆盖完整正文超限和直接裁剪入口，调用方不必重复提供已知身份。
   *
   * @return void 断言完成
   */
  public function testHandlerInheritsKnownContextAtBothEntryPoints(): void
  {
    $seen = [];
    $options = new MonitorOptions(['max_body_bytes' => 20, 'body_truncator' =>
      static function (string $body, array $context) use (&$seen): array {
        $seen[] = $context;
        return ['code' => 0];
      }]);
    foreach ([Target::REQUEST_BODY, Target::RESPONSE_BODY] as $target) {
      $scope = (new PayloadSanitizer($options, '/auth'))->forScope($target, 'POST');
      $scope->sanitizeBody(str_repeat('x', 100), 'application/json');
      $scope->truncateBody(str_repeat('x', 100), ['content_type' => 'application/json', 'is_complete' => true]);
    }
    self::assertSame(['POST', 'POST', 'POST', 'POST'], array_column($seen, 'method'));
    self::assertSame(['request', 'request', 'response', 'response'], array_column($seen, 'direction'));
    self::assertSame(['/auth', '/auth', '/auth', '/auth'], array_column($seen, 'path'));
  }

  /**
   * 显式覆盖优先且不污染原作用域
   *
   * 覆盖后的响应使用 GET 策略，原 POST 请求作用域依旧关闭采集。
   *
   * @return void 断言完成
   */
  public function testExplicitDirectionAndMethodOverrideRemainSupported(): void
  {
    $seen = [];
    $options = new MonitorOptions(['max_body_bytes' => 20,
      'privacy' => ['rules' => [['paths' => ['/auth'], 'methods' => ['POST'],
        'request' => ['body' => ['capture' => false]],
      ]]], 'body_truncator' => static function (string $body, array $context) use (&$seen): array {
        $seen = $context;
        return ['code' => 0];
      }]);
    $scope = (new PayloadSanitizer($options, '/auth'))->forScope(Target::REQUEST_BODY, 'POST');
    $body = $scope->sanitizeBody(str_repeat('x', 100), 'application/json', ['direction' => 'response', 'method' => 'GET']);
    self::assertSame(['code' => 0], $body['content']);
    self::assertSame('GET', $seen['method']);
    self::assertSame('response', $seen['direction']);
    self::assertFalse($scope->canCapture());
    self::assertSame('[CAPTURE_DISABLED]', $scope->sanitizeBody('0', 'application/json')['content']);
  }

  /**
   * 无明确正文方向时保持默认响应行为
   *
   * 兼容独立脱敏器的历史入口，未知方法仍为空串。
   *
   * @return void 断言完成
   */
  public function testUnscopedSanitizerDefaultsToResponse(): void
  {
    $options = new MonitorOptions(['privacy' => ['defaults' => ['request' => ['body' => ['capture' => false]]]]]);
    $body = (new PayloadSanitizer($options))->sanitizeBody('{"code":0}', 'application/json');
    self::assertSame(['code' => 0], $body['content']);
  }
}
