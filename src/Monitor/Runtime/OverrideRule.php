<?php

declare(strict_types=1);

/* 职责：不可变的精确接口覆盖规则，不支持实际路径或前缀匹配。 */

namespace Viswoole\Monitor\Runtime;

use InvalidArgumentException;
use Viswoole\Router\RouteIdentity;

/** 版本由 MonitorRuntime 分配，项目按实例标识及版本关联排障记录。 */
final readonly class OverrideRule
{
  /**
   * 创建已发布规则
   * @param array{id:string,threshold_ms:float,version:int} $definition 稳定标量
   * @param RouteIdentity $route 目录中的精确身份
   * @throws InvalidArgumentException ID 或阈值非法
   */
  public function __construct(private array $definition, public RouteIdentity $route)
  {
    if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $definition['id']) !== 1) {
      throw new InvalidArgumentException('覆盖规则 id 必须为 1–64 个字母、数字或 _.:-');
    }
    self::validateThreshold($definition['threshold_ms']);
    $this->id = $definition['id'];
    $this->thresholdMs = $definition['threshold_ms'];
    $this->version = $definition['version'];
  }

  public string $id;
  public float $thresholdMs;
  public int $version;

  /**
   * 校验有限非负阈值
   * @param float $threshold 毫秒数
   * @return void
   * @throws InvalidArgumentException 阈值非法
   */
  public static function validateThreshold(float $threshold): void
  {
    if (!is_finite($threshold) || $threshold < 0) { throw new InvalidArgumentException('采集阈值必须为有限的非负毫秒数'); }
  }

  /** @return array{id:string,version:int,threshold_ms:float} 最小分流身份 */
  public function identity(): array
  {
    return ['id' => $this->id, 'version' => $this->version, 'threshold_ms' => $this->thresholdMs];
  }

  /** @return array<string,mixed> 共享存储记录 */
  public function toArray(): array { return $this->identity() + ['route' => $this->route->toArray()]; }
}
