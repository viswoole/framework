<?php

declare(strict_types=1);

/* 职责：保存一份完整且不可变的运行配置，按接口预建候选索引。 */

namespace Viswoole\Monitor\Runtime;

/** 只在管理更新时重建索引；请求读取不遍历无关规则。 */
final readonly class RuntimeState
{
  /** @var array<string,list<OverrideRule>> */
  public array $byRoute;
  public float $thresholdMs;
  public string $runtimeId;
  public int $version;

  /**
   * 创建一致配置
   * @param array{threshold_ms:float,runtime_id:string,version:int} $header 全局状态
   * @param array<string,OverrideRule> $rules 规则集合
   */
  public function __construct(public array $header, public array $rules = [])
  {
    $this->thresholdMs = $header['threshold_ms'];
    $this->runtimeId = $header['runtime_id'];
    $this->version = $header['version'];
    $index = [];
    $sorted = $rules;
    ksort($sorted, SORT_STRING);
    foreach ($sorted as $rule) { $index[$rule->route->key()][] = $rule; }
    $this->byRoute = $index;
  }

  /**
   * 复用无动态策略时的不可变静态状态，避免每请求重复生成实例标识
   * @param float $thresholdMs 静态配置门槛
   * @return self 本进程继承的静态回退状态
   */
  public static function fallback(float $thresholdMs): self
  {
    static $state = null;
    if ($state === null || $state->thresholdMs !== $thresholdMs) { $state = self::initial($thresholdMs); }
    return $state;
  }

  /**
   * 创建新实例初始状态
   * @param float $thresholdMs 静态阈值
   * @return self 唯一运行实例身份
   */
  public static function initial(float $thresholdMs): self
  {
    return new self(['threshold_ms' => $thresholdMs, 'runtime_id' => bin2hex(random_bytes(16)), 'version' => 0]);
  }
}
