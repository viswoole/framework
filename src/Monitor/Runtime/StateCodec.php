<?php

declare(strict_types=1);

/* 职责：共享状态编码与重建，不涉及发布和锁管理。 */

namespace Viswoole\Monitor\Runtime;

use Viswoole\Router\RouteIdentity;

/** 仅处理框架生成的有界标量树。 */
final class StateCodec
{
  /**
   * 编码完整配置
   * @param RuntimeState $state 已校验状态
   * @return string 可分块写入的 JSON
   */
  public static function encode(RuntimeState $state): string
  {
    return json_encode(['header' => $state->header, 'rules' => array_values(array_map(
      static fn (OverrideRule $rule): array => $rule->toArray(),
      $state->rules
    ))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * 重建配置及接口索引
   * @param string $json 已提交配置
   * @return RuntimeState 不可变状态
   */
  public static function decode(string $json): RuntimeState
  {
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $rules = [];
    foreach ($data['rules'] as $record) {
      $route = $record['route'];
      $rules[$record['id']] = new OverrideRule([
        'id' => $record['id'], 'threshold_ms' => (float)$record['threshold_ms'], 'version' => $record['version'],
      ], new RouteIdentity($route['method'], $route['template'], $route['domain_constraints']));
    }
    $data['header']['threshold_ms'] = (float)$data['header']['threshold_ms'];
    return new RuntimeState($data['header'], $rules);
  }
}
