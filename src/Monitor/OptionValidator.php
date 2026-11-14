<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

use InvalidArgumentException;

/** 职责：严格读取启动期标量配置，不将非法字符串或小数隐式转换为有效配置。 */
final class OptionValidator
{
  /**
   * 读取布尔开关
   * @param array<string,mixed> $options 配置数组
   * @param string $key 配置名称
   * @param bool $default 缺失时的默认值
   * @return bool 原生布尔值
   */
  public static function readBoolean(array $options, string $key, bool $default): bool
  {
    $value = array_key_exists($key, $options) ? $options[$key] : $default;
    if (!is_bool($value)) { throw new InvalidArgumentException('monitor.' . $key . ' 必须是 bool'); }
    return $value;
  }

  /**
   * 读取整数限额
   * @param array<string,mixed> $options 配置数组
   * @param string $key 配置名称
   * @param int $default 缺失时的默认值
   * @return int 未经截断的整数
   */
  public static function readInteger(array $options, string $key, int $default): int
  {
    $value = array_key_exists($key, $options) ? $options[$key] : $default;
    if (!is_int($value)) { throw new InvalidArgumentException('monitor.' . $key . ' 必须是 int'); }
    return $value;
  }

  /**
   * 读取有限数值阈值
   * @param array<string,mixed> $options 配置数组
   * @param string $key 配置名称
   * @param float $default 缺失时的默认值
   * @return float 有限浮点值
   */
  public static function readNumber(array $options, string $key, float $default): float
  {
    $value = array_key_exists($key, $options) ? $options[$key] : $default;
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
      throw new InvalidArgumentException('monitor.' . $key . ' 必须是有限的 int 或 float');
    }
    return (float)$value;
  }

  /**
   * 读取可空字符串配置
   * @param array<string,mixed> $options 配置数组
   * @param string $key 配置名称
   * @return string|null 显式字符串或未配置
   */
  public static function readNullableString(array $options, string $key): ?string
  {
    $value = $options[$key] ?? null;
    if ($value !== null && (!is_string($value) || trim($value) === '')) {
      throw new InvalidArgumentException('monitor.' . $key . ' 必须是非空字符串或 null');
    }
    return $value;
  }
}
