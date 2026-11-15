<?php

declare(strict_types=1);

/* 职责：严格验证隐私配置结构，拒绝未知键以避免拼写错误静默泄漏。 */

namespace Viswoole\Monitor\Privacy;

use InvalidArgumentException;

/** 将配置组编译为明确的 Target 与 Settings。 */
final class Configuration
{
  /**
   * 验证关联数组及允许的键
   *
   * 未知配置启动失败，不将错误配置解释为允许采集。
   *
   * @param mixed $value 原始配置
   * @param list<string> $keys 允许的配置键
   * @param string $label 错误定位路径
   * @return array 已验证数组
   * @throws InvalidArgumentException 类型或键不合法
   */
  public static function object(mixed $value, array $keys, string $label): array
  {
    if (!is_array($value) || array_diff(array_keys($value), $keys) !== []) {
      throw new InvalidArgumentException($label . ' 必须为配置数组且仅允许: ' . implode(', ', $keys));
    }
    return $value;
  }

  /**
   * 验证非空字符串列表
   *
   * 区分空列表是否允许，防止规则不小心匹配所有请求。
   *
   * @param mixed $value 原始列表
   * @param string $label 错误定位路径
   * @param bool $isRequired 是否至少需要一个成员
   * @return list<string> 已验证列表
   * @throws InvalidArgumentException 列表非法
   */
  public static function strings(mixed $value, string $label, bool $isRequired = false): array
  {
    if (!is_array($value) || !array_is_list($value) || ($isRequired && $value === [])
      || !array_all($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== '')) {
      throw new InvalidArgumentException($label . ' 必须为' . ($isRequired ? '非空' : '') . '字符串列表');
    }
    return $value;
  }

  /**
   * 编译方向与分组
   *
   * metadata 只接受字段脱敏，不允许关闭状态、方法等基础元信息。
   *
   * @param mixed $value 原始方向配置
   * @param string $label 错误定位路径
   * @return array<string,Settings> 以明确作用域索引的设置
   */
  public static function groups(mixed $value, string $label): array
  {
    $directions = self::object($value, ['request', 'response', 'operation'], $label);
    $compiled = [];
    foreach ($directions as $direction => $groups) {
      $allowed = match ($direction) {
        'request' => ['body', 'query', 'headers', 'metadata'],
        'response' => ['body', 'headers', 'metadata'],
        default => ['metadata'],
      };
      foreach (self::object($groups, $allowed, $label . '.' . $direction) as $group => $settings) {
        $key = $direction . '.' . $group;
        $compiled[$key] = self::settings($settings, $label . '.' . $key, $group !== 'metadata');
      }
    }
    return $compiled;
  }

  /**
   * 编译单组设置
   *
   * 显式 null 不是默认值，错误开关不能静默启用采集。
   *
   * @param mixed $value 原始组设置
   * @param string $label 错误定位路径
   * @param bool $canDisable 是否支持采集开关
   * @return Settings 已验证设置
   */
  private static function settings(mixed $value, string $label, bool $canDisable): Settings
  {
    $settings = self::object($value, $canDisable ? ['capture', 'redact_fields'] : ['redact_fields'], $label);
    if (array_key_exists('capture', $settings) && !is_bool($settings['capture'])) {
      throw new InvalidArgumentException($label . '.capture 必须为布尔值');
    }
    $fields = self::strings(array_key_exists('redact_fields', $settings) ? $settings['redact_fields'] : [], $label . '.redact_fields');
    return new Settings($settings['capture'] ?? true, $fields);
  }
}
