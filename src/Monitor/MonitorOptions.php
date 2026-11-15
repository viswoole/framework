<?php

/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChongLin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Viswoole\Monitor;

use InvalidArgumentException;
use Viswoole\Core\Common\PathNormalizer;
use Viswoole\Monitor\Privacy\Policy;

/**
 * 监控配置值对象
 *
 * 职责：承载慢请求监控的全部配置项并在构造时完成校验，提供路径是否
 * 需要采集的判定。
 * 边界：仅负责校验采集边界，不持有请求状态；配置在服务启动时读取，
 * 静态选项修改需重启；全局阈值与覆盖规则由 MonitorRuntime 单独管理。
 */
final readonly class MonitorOptions
{
  /** @var bool 是否启用慢请求监控（总开关） */
  public bool $isEnabled;
  /** @var bool 排除路径匹配是否遵循区分大小写的路由配置 */
  public bool $isRouteCaseSensitive;
  public RequestIdOptions $requestId;
  public Policy $privacy;
  public bool $captureRequest;
  public bool $captureResponse;
  public bool $captureDatabase;
  public bool $captureCache;
  /** @var float 慢请求阈值（毫秒），达到该值才写入记录 */
  public float $thresholdMs;
  /** @var int 启动期分配的共享规则容量 */
  public int $overrideCapacity;
  /** @var int 单请求响应正文采集上限（字节），超限时尝试自定义摘要，默认不输出正文 */
  public int $maxBodyBytes;
  public BodyTruncator $bodyTruncator;
  /** @var int 单请求操作记录条数上限，超限后计入 dropped_operations */
  public int $maxOperations;
  /** @var int 请求快照 JSON 总预算，包含固定结构及汇总预留 */
  public int $maxSnapshotBytes;
  /** @var string|null 显式选择的存储实现，null 时须由应用绑定接口 */
  public ?string $writer;
  /** @var bool 是否记录 SQL 绑定值（默认隐藏，开启后仍对命名敏感参数脱敏） */
  public bool $recordBindings;
  /** @var bool 是否显式允许记录可能包含字面量凭据的原始 SQL */
  public bool $canRecordSql;
  /** @var array<string> 不采集的路径列表，支持 fnmatch 通配符 */
  public array $excludePaths;

  /**
   * 构造时完成验证
   *
   * 配置错误在启动时暴露，避免请求中途才发现无法分配采集预算。
   *
   * @param array<string,mixed> $options monitor 配置
   * @param bool $isRouteCaseSensitive router.case_sensitive 启动期配置
   * @throws InvalidArgumentException 配置类型非法、阈值非有限数或采集预算越界时抛出
   */
  public function __construct(array $options = [], bool $isRouteCaseSensitive = false)
  {
    $this->validateLegacyOptions($options);
    $this->isRouteCaseSensitive = $isRouteCaseSensitive;
    $this->isEnabled = OptionValidator::readBoolean($options, 'enabled', false);
    $this->captureRequest = OptionValidator::readBoolean($options, 'capture_request', true);
    $this->captureResponse = OptionValidator::readBoolean($options, 'capture_response', true);
    $this->captureDatabase = OptionValidator::readBoolean($options, 'capture_database', true);
    $this->captureCache = OptionValidator::readBoolean($options, 'capture_cache', true);
    $this->thresholdMs = OptionValidator::readNumber($options, 'threshold_ms', 1000);
    $this->overrideCapacity = OptionValidator::readInteger($options, 'override_capacity', 256);
    $this->maxBodyBytes = OptionValidator::readInteger($options, 'max_body_bytes', 16384);
    $this->maxOperations = OptionValidator::readInteger($options, 'max_operations', 200);
    $this->maxSnapshotBytes = OptionValidator::readInteger($options, 'max_snapshot_bytes', 262144);
    $this->writer = OptionValidator::readNullableString($options, 'writer');
    $this->bodyTruncator = new BodyTruncator($options['body_truncator'] ?? null);
    $this->requestId = new RequestIdOptions($options['request_id'] ?? []);
    $this->privacy = new Policy(
      array_key_exists('privacy', $options) ? $options['privacy'] : [],
      $isRouteCaseSensitive
    );
    $this->recordBindings = OptionValidator::readBoolean($options, 'record_bindings', false);
    $this->canRecordSql = OptionValidator::readBoolean($options, 'record_sql', false);
    $this->excludePaths = $this->normalizeExcludedPaths($options);
    $this->validateLimits();
  }

  /**
   * 拒绝已移除的脱敏配置，避免错误配置被静默接受
   * @param array<string,mixed> $options 原始配置
   * @return void
   */
  private function validateLegacyOptions(array $options): void
  {
    foreach (['sensitive_keys', 'sensitive_keys_by_path'] as $legacyKey) {
      if (array_key_exists($legacyKey, $options)) {
        throw new InvalidArgumentException(
          'monitor.' . $legacyKey . ' 已移除，请迁移至 monitor.privacy（capture 与 redact_fields 分离）'
        );
      }
    }
  }

  /**
   * 在启动期验证容量与预算边界，避免请求内分配失败
   * @return void
   */
  private function validateLimits(): void
  {
    if ($this->overrideCapacity < 1) {
      throw new InvalidArgumentException('monitor.override_capacity 必须大于零');
    }
    if (!is_finite($this->thresholdMs) || $this->thresholdMs < 0
      || $this->maxBodyBytes < 0 || $this->maxOperations < 0 || $this->maxSnapshotBytes < 16384) {
      throw new InvalidArgumentException(
        'monitor 阈值与采集上限必须非负，总快照预算至少 16384 字节'
      );
    }
  }

  /**
   * 验证并归一化排除路径配置
   *
   * 不能把列表成员错误延迟到请求内，造成每次采集都异常降级。
   *
   * @param array<string,mixed> $options 原始配置
   * @return array<string> 遵循路由大小写配置的匹配模式
   * @throws InvalidArgumentException 列表含空字符串或非字符串时抛出
   */
  private function normalizeExcludedPaths(array $options): array
  {
    $values = $options['exclude_paths'] ?? [];
    if (!is_array($values) || !array_all(
      $values,
      static fn(mixed $value): bool => is_string($value) && trim($value) !== ''
    )) {
      throw new InvalidArgumentException('monitor.exclude_paths 必须是非空字符串列表');
    }
    return array_map(
      fn(string $pattern): string => PathNormalizer::forMatching($pattern, $this->isRouteCaseSensitive),
      $values
    );
  }

  /**
   * 路由策略固定前的来源预留上限，固定后由 CaptureBudget 核减
   * @return int 不超过快照总预算四分之一的字节数
   */
  public function captureMetadataLimit(): int
  {
    return intdiv($this->maxSnapshotBytes, 4);
  }

  /**
   * 单请求正文采集上限
   *
   * 正文缓存、超限摘要共用同一口径：除 max_body_bytes 外，还为快照总预算
   * 预留四分之一，防止正文独占预算挤掉元数据。请求、响应与摘要三条采集
   * 路径必须引用本方法，避免口径分叉后上限不一致。
   *
   * @return int 正文字节上限
   */
  public function bodyLimit(): int
  {
    return min($this->maxBodyBytes, intdiv($this->maxSnapshotBytes, 4));
  }

  /**
   * 判断指定路径是否需要采集
   *
   * @param string $path 请求路径
   * @param PathPolicyMatcher|null $matcher 已解析的同一路径匹配器，避免重复解码
   * @return bool 未命中任何排除规则时返回 true
   */
  public function shouldCollect(string $path, ?PathPolicyMatcher $matcher = null): bool
  {
    if (!$this->isEnabled) {
      return false;
    }
    $matcher ??= new PathPolicyMatcher($path, $this->isRouteCaseSensitive);
    return array_all(
      $this->excludePaths,
      static fn(string $pattern): bool => !$matcher->matches($pattern)
    );
  }

}
