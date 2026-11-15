<?php

declare(strict_types=1);

/* 职责：集中字段名规范化及内置保护，不做子串或容器匹配。 */

namespace Viswoole\Monitor\Privacy;

/** 精确字段匹配，兼容驼峰、下划线及连字符命名。 */
final class Fields
{
  public const array BUILT_IN = [
    'password', 'passwd', 'pwd', 'secret', 'token', 'authorization', 'cookie', 'set-cookie',
    'api_key', 'access_token', 'refresh_token', 'sms_code', 'verify_code', 'verification_code',
    'captcha', 'ticket', 'otp', 'old_password', 'new_password', 'password_hash', 'captcha_ticket',
    'captcha_randstr', 'x-authorization', 'x-api-key', 'x-captcha-ticket', 'x-captcha-randstr',
    // 旧子串机制覆盖过的高频复合变体，精确匹配下必须显式枚举，否则静默泄漏
    'confirm_password', 'password_confirm', 'db_password', 'user_password',
    'auth_token', 'user_token', 'x-token', 'jwt_token',
    'secret_key', 'client_secret', 'app_secret', 'ticket_id',
  ];

  /**
   * 规范化完整字段名
   *
   * 首尾空白与前导冒号（PDO 参数）统一移除；不移除其他字符以免扩展字段匹配范围。
   * 查找表与待判定字段名共用本方法，配置带空格不会产生静默失配。
   *
   * @param string $name 原始字段名
   * @return string 用于完全相等比较的键
   */
  public static function normalize(string $name): string
  {
    return strtolower(str_replace(['_', '-'], '', ltrim(trim($name), ':')));
  }
}
