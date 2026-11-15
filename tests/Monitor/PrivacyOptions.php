<?php

declare(strict_types=1);

/* 职责：为既有边界测试构建显式分组配置，不提供运行时旧配置兼容。 */

namespace Viswoole\Tests\Monitor;

use Viswoole\Monitor\Privacy\Target;

/** 隐私测试配置工厂，简化跨分组保护的测试数据。 */
final class PrivacyOptions
{
  /**
   * 构造跨组字段测试设置
   *
   * 只用于验证所有来源的共同边界，运行时配置仍需显式方向与分组。
   *
   * @param list<string> $fields 精确字段名
   * @return array 显式分组默认设置
   */
  public static function redact(array $fields): array
  {
    $defaults = [];
    foreach (Target::cases() as $target) {
      [$direction, $group] = explode('.', $target->value);
      $defaults[$direction][$group] = ['redact_fields' => $fields];
    }
    return ['defaults' => $defaults];
  }

  /**
   * 构造路径测试设置
   *
   * 为既有路径边界用例生成所有分组的追加保护。
   *
   * @param array<string,list<string>> $paths 路径测试样本
   * @return array 显式追加规则
   */
  public static function paths(array $paths): array
  {
    $rules = [];
    foreach ($paths as $path => $fields) {
      $rules[] = ['paths' => [$path]] + self::redact($fields)['defaults'];
    }
    return ['rules' => $rules];
  }

  /**
   * 关闭双向正文采集
   *
   * 不使用字段名模拟采集开关，其他字段仍按明确分组处理。
   *
   * @param list<string> $fields 其他字段规则
   * @return array 明确关闭双向正文的设置
   */
  public static function disableBodies(array $fields = []): array
  {
    $privacy = self::redact($fields);
    $privacy['defaults']['request']['body']['capture'] = false;
    $privacy['defaults']['response']['body']['capture'] = false;
    return $privacy;
  }

  /**
   * 关闭双向标头采集
   *
   * 对应采集状态为 NOT_CAPTURED，不冒充字段已脱敏。
   *
   * @return array 明确关闭双向标头采集的设置
   */
  public static function disableHeaders(): array
  {
    return ['defaults' => ['request' => ['headers' => ['capture' => false]], 'response' => ['headers' => ['capture' => false]]]];
  }
}
