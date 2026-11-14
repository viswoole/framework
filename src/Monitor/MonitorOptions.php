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

/**
 * 监控配置值对象
 *
 * 职责：承载慢请求监控的全部配置项并在构造时完成校验，提供路径是否
 * 需要采集的判定。
 * 边界：仅负责校验采集边界，不持有请求状态；配置在服务启动时读取，
 * 修改后需重启服务生效。
 */
final readonly class MonitorOptions
{
  /** @var bool 是否启用慢请求监控（总开关） */
  public bool $isEnabled;
  /** @var bool 排除路径匹配是否遵循区分大小写的路由配置 */
  public bool $isRouteCaseSensitive;
  public RequestIdOptions $requestId;
  private SensitiveKeyPolicy $sensitiveKeyPolicy;
  public bool $captureRequest;
  public bool $captureResponse;
  public bool $captureDatabase;
  public bool $captureCache;
  /** @var float 慢请求阈值（毫秒），达到该值才写入记录 */
  public float $thresholdMs;
  /** @var int 单请求响应正文采集上限（字节），超限正文完全不输出 */
  public int $maxBodyBytes;
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
  /** @var array<string> 敏感字段名规则（与内置规则合并，命中即脱敏） */
  public array $sensitiveKeys;

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
    $this->isRouteCaseSensitive = $isRouteCaseSensitive;
    $this->isEnabled = OptionValidator::readBoolean($options, 'enabled', false);
    $this->captureRequest = OptionValidator::readBoolean($options, 'capture_request', true);
    $this->captureResponse = OptionValidator::readBoolean($options, 'capture_response', true);
    $this->captureDatabase = OptionValidator::readBoolean($options, 'capture_database', true);
    $this->captureCache = OptionValidator::readBoolean($options, 'capture_cache', true);
    $this->thresholdMs = OptionValidator::readNumber($options, 'threshold_ms', 1000);
    $this->maxBodyBytes = OptionValidator::readInteger($options, 'max_body_bytes', 16384);
    $this->maxOperations = OptionValidator::readInteger($options, 'max_operations', 200);
    $this->maxSnapshotBytes = OptionValidator::readInteger($options, 'max_snapshot_bytes', 262144);
    $this->writer = OptionValidator::readNullableString($options, 'writer');
    $this->requestId = new RequestIdOptions($options['request_id'] ?? []);
    $this->sensitiveKeyPolicy = new SensitiveKeyPolicy($options['sensitive_keys_by_path'] ?? [], $isRouteCaseSensitive);
    $this->recordBindings = OptionValidator::readBoolean($options, 'record_bindings', false);
    $this->canRecordSql = OptionValidator::readBoolean($options, 'record_sql', false);
    $this->validateLists($options);
    $this->excludePaths = array_map(
      static fn (string $pattern): string => PathNormalizer::forMatching($pattern, $isRouteCaseSensitive),
      $options['exclude_paths'] ?? []
    );
    $this->sensitiveKeys = array_merge([
      'password', 'passwd', 'pwd', 'secret', 'token', 'authorization',
      'cookie', 'set-cookie', 'api_key', 'access_token', 'refresh_token',
      'sms_code', 'verify_code', 'verification_code', 'captcha', 'ticket', 'otp',
    ], $options['sensitive_keys'] ?? []);
    if (!is_finite($this->thresholdMs) || $this->thresholdMs < 0
      || $this->maxBodyBytes < 0 || $this->maxOperations < 0 || $this->maxSnapshotBytes < 16384) {
      throw new InvalidArgumentException('monitor 阈值与采集上限必须非负，总快照预算至少 16384 字节');
    }
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
    if (!$this->isEnabled) { return false; }
    $matcher ??= new PathPolicyMatcher($path, $this->isRouteCaseSensitive);
    return array_all($this->excludePaths, static fn (string $pattern): bool => !$matcher->matches($pattern));
  }
  /**
   * 解析请求路径的有效脱敏字段
   *
   * @param string $path 原始请求路径
   * @param PathPolicyMatcher|null $matcher 已解析的同一路径匹配器，避免重复解码
   * @return list<string> 内置、全局和匹配路径的字段并集
   */
  public function sensitiveKeysForPath(string $path, ?PathPolicyMatcher $matcher = null): array
  {
    return $this->sensitiveKeyPolicy->resolve($this->sensitiveKeys, $path, $matcher);
  }

  /**
   * 验证路径及敏感词配置
   *
   * 不能把列表成员错误延迟到请求内，造成每次采集都异常降级。
   *
   * @param array<string,mixed> $options 原始配置
   * @return void
   * @throws InvalidArgumentException 列表含空字符串或非字符串时抛出
   */
  private function validateLists(array $options): void
  {
    foreach (['exclude_paths', 'sensitive_keys'] as $key) {
      $values = $options[$key] ?? [];
      if (!is_array($values) || !array_all($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== '')) {
        throw new InvalidArgumentException('monitor.' . $key . ' 必须是非空字符串列表');
      }
    }
  }

}
