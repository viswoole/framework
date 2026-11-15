<?php

declare(strict_types=1);

/* 职责：验证显式分组隐私配置、精确字段与保护并集，不读取真实 HTTP。 */

namespace Viswoole\Tests\Monitor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\MonitorOptions;
use Viswoole\Monitor\PayloadSanitizer;
use Viswoole\Monitor\Privacy\Target;
use Viswoole\Monitor\RequestTrace;

/** 隐私规则对外配置契约。 */
final class PrivacyPolicyTest extends TestCase
{
  /**
   * 验证方向及字段语义
   *
   * body 只是业务字段，验证码规则不污染响应业务码或其他方法。
   *
   * @return void 断言完成
   */
  public function testBodyFieldAndDirectionAreIndependent(): void
  {
    $options = new MonitorOptions(['privacy' => ['rules' => [[
      'paths' => ['/admin/auth/*'], 'methods' => ['put'],
      'request' => ['body' => ['redact_fields' => ['body', 'code', 'oldCode']]],
    ]]]]);
    $base = new PayloadSanitizer($options, '/ADMIN/auth/profile/phone.html');
    $request = $base->forScope(Target::REQUEST_BODY, 'PUT');
    $safe = $request->sanitize(['body' => ['secret' => 'private'], 'code' => '123456',
      'old_code' => '654321', 'business_code' => 0, 'data' => ['old-code' => '654321']]);
    self::assertTrue($request->canCapture());
    self::assertSame('[REDACTED]', $safe['body']);
    self::assertSame('[REDACTED]', $safe['code']);
    self::assertSame('[REDACTED]', $safe['old_code']);
    self::assertSame('[REDACTED]', $safe['data']['old-code']);
    self::assertSame(0, $safe['business_code']);
    self::assertSame(0, $base->forScope(Target::RESPONSE_BODY, 'PUT')->sanitize(['code' => 0])['code']);
    self::assertSame('123456', $base->forScope(Target::REQUEST_BODY, 'POST')->sanitize(['code' => '123456'])['code']);
  }

  /**
   * 默认设置与命中规则取保护并集
   *
   * 规则顺序不改变字段集合，任意关闭不能被后面的 true 重新开启。
   *
   * @return void 断言完成
   */
  public function testCaptureVetoAndFieldUnionAreOrderIndependent(): void
  {
    $rules = [
      ['paths' => ['/private/*'], 'request' => ['body' => ['capture' => false, 'redact_fields' => ['mobile']]]],
      ['paths' => ['/private/detail'], 'request' => ['body' => ['capture' => true, 'redact_fields' => ['code']]]],
    ];
    foreach ([$rules, array_reverse($rules)] as $ordered) {
      $options = new MonitorOptions(['privacy' => [
        'defaults' => ['request' => ['body' => ['redact_fields' => ['phone']]]], 'rules' => $ordered,
      ]]);
      $scope = (new PayloadSanitizer($options, '/private/detail'))->forScope(Target::REQUEST_BODY, 'POST');
      self::assertFalse($scope->canCapture());
      foreach (['phone', 'mobile', 'code', 'password'] as $key) { self::assertTrue($scope->isSensitiveField($key)); }
      self::assertTrue((new PayloadSanitizer($options, '/public'))->forScope(Target::REQUEST_BODY)->canCapture());
    }
  }

  /**
   * 明确区分未采集与脱敏
   *
   * 请求 body=false 同时阻止 post 副本，query/headers 和响应独立控制。
   *
   * @return void 断言完成
   */
  public function testDisabledGroupsDoNotHideOtherDirections(): void
  {
    $options = new MonitorOptions(['threshold_ms' => 0, 'privacy' => ['defaults' => [
      'request' => ['body' => ['capture' => false], 'query' => ['capture' => false]],
      'response' => ['headers' => ['capture' => false]],
    ]]]);
    $trace = new RequestTrace($options, ['method' => 'POST', 'path' => '/',
      'post' => ['credential' => 'private'], 'query' => ['credential' => 'private'], 'body' => ['credential' => 'private']]);
    $trace->updateResponse(['content_type' => 'application/json']);
    $trace->updateResponseHeader('X-Private', 'private');
    $trace->appendResponse('{"code":0}', true);
    $snapshot = $trace->finish();
    self::assertSame('[CAPTURE_DISABLED]', $snapshot['request']['body']['content']);
    self::assertSame('[CAPTURE_DISABLED]', $snapshot['request']['post']);
    self::assertSame('[CAPTURE_DISABLED]', $snapshot['request']['query']);
    self::assertSame('[CAPTURE_DISABLED]', $snapshot['response']['headers']);
    self::assertSame(['code' => 0], $snapshot['response']['body']['content']);
  }

  /**
   * 内置复合凭据变体无需配置即受保护
   *
   * 精确匹配取代子串后，旧机制覆盖过的高频复合字段必须显式内置，否则静默泄漏。
   *
   * @return void 断言完成
   */
  public function testBuiltInCompositeFieldsAreProtectedWithoutConfig(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions());
    $safe = $sanitizer->sanitize([
      'confirm_password' => 'synthetic', 'auth_token' => 'synthetic', 'x-token' => 'synthetic',
      'db_password' => 'synthetic', 'client_secret' => 'synthetic', 'ticket_id' => 'synthetic',
    ]);
    foreach (array_keys($safe) as $key) { self::assertSame('[REDACTED]', $safe[$key]); }
    // business_code 等普通业务字段不受牵连
    self::assertSame(0, $sanitizer->sanitize(['business_code' => 0])['business_code']);
  }

  /**
   * 配置首尾空白不产生静默失配
   *
   * 归一化统一移除首尾空白，带空格的字段名仍然命中同一保护。
   *
   * @return void 断言完成
   */
  public function testFieldNamesAreNormalizedRegardlessOfPadding(): void
  {
    $sanitizer = new PayloadSanitizer(new MonitorOptions(['privacy' => PrivacyOptions::redact([' code '])]));
    self::assertTrue($sanitizer->isSensitiveField('code'));
    self::assertTrue($sanitizer->isSensitiveField(' code '));
    self::assertSame('[REDACTED]', $sanitizer->sanitize(['code' => 'synthetic'])['code']);
  }

  /**
   * 拒绝旧配置
   *
   * 即使旧配置为空或为 null 也需要迁移，禁止两套机制并存。
   *
   * @return void 断言完成
   */
  public function testLegacyOptionsFailWithMigrationMessage(): void
  {
    foreach (['sensitive_keys', 'sensitive_keys_by_path'] as $key) {
      foreach ([[], null] as $value) {
        try {
          new MonitorOptions([$key => $value]);
          self::fail('旧配置必须拒绝');
        } catch (InvalidArgumentException $error) {
          self::assertStringContainsString('monitor.privacy', $error->getMessage());
        }
      }
    }
  }

  /**
   * 拒绝未知或错误配置
   *
   * 不把拼错的分组、错误类型和空路径列表静默当作允许采集。
   *
   * @return void 断言完成
   */
  public function testInvalidPrivacyConfigurationFailsAtStartup(): void
  {
    $invalid = [null, ['unknown' => true], ['defaults' => null], ['rules' => null],
      ['defaults' => ['request' => ['boddy' => []]]],
      ['defaults' => ['request' => ['body' => ['capture' => 'false']]]],
      ['defaults' => ['request' => ['body' => ['redact_fields' => null]]]],
      ['defaults' => ['request' => ['body' => ['redact_fields' => [null]]]]],
      ['defaults' => ['response' => ['metadata' => ['capture' => false]]]],
      ['rules' => [['paths' => [], 'request' => ['body' => ['capture' => false]]]]],
      ['rules' => [['paths' => ['/'], 'methods' => [], 'request' => []]]],
    ];
    foreach ($invalid as $privacy) {
      try {
        new MonitorOptions(['privacy' => $privacy]);
        self::fail('错误隐私配置必须拒绝');
      } catch (InvalidArgumentException $error) {
        self::assertStringContainsString('privacy', $error->getMessage());
      }
    }
  }
}
