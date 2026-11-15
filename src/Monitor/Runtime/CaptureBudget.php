<?php

declare(strict_types=1);

/* 职责：在管理发布前验证同一接口的完整来源上界，不截断命中规则。 */

namespace Viswoole\Monitor\Runtime;

use OverflowException;
use Viswoole\Monitor\SnapshotBudget;
use Viswoole\Router\RouteIdentity;
use WeakMap;

/** 按精确接口分组核算，分散接口之间不相互消耗来源配额。 */
final class CaptureBudget
{
  /** @var WeakMap<RuntimeState,array<string,int>>|null 仅缓存不可变配置的派生字节数 */
  private static ?WeakMap $reservations = null;
  /**
   * 固定策略后计算其完整来源实际编码上界
   * @param CapturePolicy $policy 不再受运行时更新影响的请求策略
   * @return int 全部候选命中及最长布尔标记需要的字节
   */
  public static function forPolicy(CapturePolicy $policy): int
  {
    self::$reservations ??= new WeakMap();
    $key = ($policy->route?->key() ?? 'global') . ':' . ini_get('serialize_precision');
    $entries = self::$reservations[$policy->state] ?? [];
    if (isset($entries[$key])) { return $entries[$key]; }
    $data = $policy->describe(PHP_FLOAT_MAX);
    // false 比 true 多一个字节；规则、阈值、版本均已固定，不再按未来版本预留。
    $data['matched_global'] = false;
    $entries[$key] = SnapshotBudget::size($data);
    self::$reservations[$policy->state] = $entries;
    return $entries[$key];
  }

  /**
   * 验证最坏命中来源可容纳
   * @param RuntimeState $state 待发布配置
   * @param RouteIdentity $route 新增或替换规则的目标
   * @param int $limit 来源预留字节
   * @return void
   * @throws OverflowException 来源信息超出预算
   */
  public static function assertFits(RuntimeState $state, RouteIdentity $route, int $limit): void
  {
    $data = (new CapturePolicy($state, $route))->describe(PHP_FLOAT_MAX);
    // 数字 JSON 最长采用保守常数；不能只用 PHP_FLOAT_MAX 猜测所有小数编码长度。
    $data['config_version'] = PHP_INT_MAX;
    $data['global_threshold_ms'] = str_repeat('9', 32);
    $data['effective_threshold_ms'] = str_repeat('9', 32);
    foreach ($data['matched_overrides'] as &$match) {
      $match['version'] = PHP_INT_MAX;
      $match['threshold_ms'] = str_repeat('9', 32);
    }
    unset($match);
    if (SnapshotBudget::size($data) > $limit) { throw new OverflowException('接口命中信息超过快照来源预算'); }
  }
}
