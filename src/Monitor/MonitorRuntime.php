<?php

declare(strict_types=1);

/* 职责：动态管理全局阈值及精确接口规则，项目负责授权、排期和持久化。 */

namespace Viswoole\Monitor;

use InvalidArgumentException;
use OverflowException;
use Swoole\Table;
use Viswoole\Monitor\Runtime\CaptureBudget;
use Viswoole\Monitor\Runtime\CapturePolicy;
use Viswoole\Monitor\Runtime\MemoryStateStore;
use Viswoole\Monitor\Runtime\OverrideRule;
use Viswoole\Monitor\Runtime\RuntimeState;
use Viswoole\Monitor\Runtime\SharedStateStore;
use Viswoole\Monitor\Runtime\StateStore;
use Viswoole\Router\InterfaceCatalog;
use Viswoole\Router\RouteIdentity;

/** 同一服务实例共享，静态配置与不可变规则不保存请求态。 */
final readonly class MonitorRuntime
{
  private StateStore $store;

  /**
   * 主进程初始化管理器
   * @param MonitorOptions $options 启动预算与容量
   * @param InterfaceCatalog $catalog 真实路由目录，启动时可尚未就绪
   * @param StateStore|null $store 内部存储替换点
   */
  public function __construct(
    private MonitorOptions $options,
    private InterfaceCatalog $catalog,
    ?StateStore $store = null
  )
  {
    $this->store = $store ?? (class_exists(Table::class)
      ? new SharedStateStore($options->thresholdMs, $options->overrideCapacity)
      : new MemoryStateStore(RuntimeState::initial($options->thresholdMs)));
  }

  /** @return float 当前全局阈值 */
  public function getThresholdMs(): float
  {
    return $this->store->read()->thresholdMs;
  }

  /**
   * 原子更新全局阈值，相同值不推进版本
   * @param float $thresholdMs 有限非负毫秒值
   * @return void
   * @throws InvalidArgumentException 阈值非法
   */
  public function setThresholdMs(float $thresholdMs): void
  {
    OverrideRule::validateThreshold($thresholdMs);
    $this->store->update(static function (RuntimeState $state) use ($thresholdMs): RuntimeState {
      if ($state->thresholdMs === $thresholdMs) {
        return $state;
      }
      return new RuntimeState(self::nextHeader($state, $thresholdMs), $state->rules);
    });
  }

  /**
   * 分配本实例不可复用配置版本
   * @param RuntimeState $state 旧配置
   * @param float $thresholdMs 新阈值
   * @return array{threshold_ms:float,runtime_id:string,version:int} 新版本头
   * @throws OverflowException 计数溢出
   */
  private static function nextHeader(RuntimeState $state, float $thresholdMs): array
  {
    if ($state->version === PHP_INT_MAX) {
      throw new OverflowException('监控配置版本已耗尽');
    }
    return ['threshold_ms' => $thresholdMs, 'runtime_id' => $state->runtimeId, 'version' => $state->version + 1];
  }

  /**
   * 新增或整体替换规则
   * @param string $id 有界内部标识
   * @param RouteIdentity $route 唯一已注册接口
   * @param float $thresholdMs 覆盖阈值
   * @return OverrideRule 已分配版本的规则
   * @throws OverflowException 容量、版本或预算不足
   * @throws InvalidArgumentException 目标或规则非法
   */
  public function setOverride(string $id, RouteIdentity $route, float $thresholdMs): OverrideRule
  {
    $this->catalog->assertRegistered($route);
    $next = $this->store->update(
      function (RuntimeState $state) use ($id, $route, $thresholdMs): RuntimeState {
        if (!isset($state->rules[$id]) && count(
          $state->rules
        ) >= $this->options->overrideCapacity) {
          throw new OverflowException('监控覆盖规则容量已满');
        }
        $header = self::nextHeader($state, $state->thresholdMs);
        $rule = new OverrideRule(
          ['id' => $id, 'threshold_ms' => $thresholdMs, 'version' => $header['version']],
          $route
        );
        $rules = $state->rules;
        $rules[$id] = $rule;
        $next = new RuntimeState($header, $rules);
        CaptureBudget::assertFits($next, $route, $this->options->captureMetadataLimit());
        return $next;
      }
    );
    return $next->rules[$id];
  }

  /**
   * 删除规则，不存在时保持版本不变
   * @param string $id 规则 ID
   * @return bool 是否删除
   */
  public function removeOverride(string $id): bool
  {
    $hasRemoved = false;
    $this->store->update(
      static function (RuntimeState $state) use ($id, &$hasRemoved): RuntimeState {
        // 版本冲突时转换可能重跑，必须由本次依据重置返回结果。
        $hasRemoved = isset($state->rules[$id]);
        if (!$hasRemoved) {
          return $state;
        }
        $rules = $state->rules;
        unset($rules[$id]);
        return new RuntimeState(self::nextHeader($state, $state->thresholdMs), $rules);
      }
    );
    return $hasRemoved;
  }

  /** @return list<OverrideRule> 规则独立不可变值 */
  public function getOverrides(): array
  {
    return array_values($this->store->read()->rules);
  }

  /** @return RuntimeState 一次一致性读取全局值、实例、版本与规则 */
  public function getState(): RuntimeState
  {
    return $this->store->read();
  }

  /**
   * 固定入口或成功匹配后的请求策略
   * @param RouteIdentity|null $route 可信接口，null 时仅全局阈值
   * @return CapturePolicy 不随之后的共享更新改变
   */
  public function resolve(?RouteIdentity $route = null): CapturePolicy
  {
    return new CapturePolicy($this->store->read(), $route);
  }
}
