<?php

declare(strict_types=1);

/* 职责：校验路径脱敏规则，按请求路径合并有效敏感字段，不保存请求态。 */

namespace Viswoole\Monitor;

use InvalidArgumentException;
use Viswoole\Core\Common\PathNormalizer;

/**
 * 请求路径脱敏策略
 *
 * 内置、全局和全部命中规则取并集，不支持撤销已有保护。
 * 只归一化一次原始路径，避免重复解码改变编码斜线和资源边界。
 */
final readonly class SensitiveKeyPolicy
{
  /** @var list<array{pattern:string,keys:list<string>}> 启动期验证后的规则 */
  private array $rules;

  /**
   * 校验并编译路径规则
   *
   * @param mixed $rules path 模式到敏感字段列表的映射
   * @param bool $isCaseSensitive 路由大小写配置
   * @throws InvalidArgumentException 模式或敏感字段列表非法
   */
  public function __construct(mixed $rules, private bool $isCaseSensitive)
  {
    if (!is_array($rules)) { throw new InvalidArgumentException('monitor.sensitive_keys_by_path 必须是路径到字段列表的映射'); }
    $compiled = [];
    foreach ($rules as $pattern => $keys) {
      if (!is_string($pattern) || trim($pattern) === '' || !is_array($keys)
        || !array_is_list($keys) || !array_all($keys, static fn (mixed $key): bool => is_string($key) && trim($key) !== '')) {
        throw new InvalidArgumentException('monitor.sensitive_keys_by_path 必须使用非空路径模式和字符串字段列表');
      }
      $compiled[] = ['pattern' => PathNormalizer::forMatching($pattern, $isCaseSensitive), 'keys' => $keys];
    }
    $this->rules = $compiled;
  }

  /**
   * 解析某次请求的有效字段
   *
   * @param list<string> $global 内置和全局敏感字段
   * @param string $path 原始请求路径
   * @param PathPolicyMatcher|null $matcher 已解析的同一路径匹配器，避免重复解码
   * @return list<string> 去重后的字段并集
   */
  public function resolve(array $global, string $path, ?PathPolicyMatcher $matcher = null): array
  {
    $matcher ??= new PathPolicyMatcher($path, $this->isCaseSensitive);
    foreach ($this->rules as $rule) {
      if ($matcher->matches($rule['pattern'])) { $global = array_merge($global, $rule['keys']); }
    }
    return array_values(array_unique($global));
  }
}
