<?php

declare(strict_types=1);

/* 职责：编译并匹配单条隐私规则，不保存请求状态。 */

namespace Viswoole\Monitor\Privacy;

use Viswoole\Core\Common\PathNormalizer;
use Viswoole\Monitor\PathPolicyMatcher;

/** 路径、方法及方向分组明确的追加保护规则。 */
final readonly class Rule
{
  /** @var list<string> 已按路由大小写规则编译的路径模式 */
  private array $paths;
  /** @var list<string> 大写方法，空列表表示不限制方法 */
  private array $methods;
  /** @var array<string,Settings> 明确作用域对应的追加保护 */
  public array $groups;

  /**
   * 编译单条规则
   *
   * 缺少 methods 表示所有方法，显式空列表视为错误。
   *
   * @param mixed $value 原始规则
   * @param bool $isCaseSensitive 路由大小写策略
   */
  public function __construct(mixed $value, bool $isCaseSensitive)
  {
    $rule = Configuration::object($value, ['paths', 'methods', 'request', 'response', 'operation'], 'monitor.privacy.rules[]');
    $paths = Configuration::strings($rule['paths'] ?? null, 'monitor.privacy.rules[].paths', true);
    $this->paths = array_map(static fn (string $path): string => PathNormalizer::forMatching($path, $isCaseSensitive), $paths);
    $this->methods = array_key_exists('methods', $rule)
      ? array_map(strtoupper(...), Configuration::strings($rule['methods'], 'monitor.privacy.rules[].methods', true)) : [];
    $this->groups = Configuration::groups(array_diff_key($rule, ['paths' => true, 'methods' => true]), 'monitor.privacy.rules[]');
  }

  /**
   * 判断规则是否命中请求
   *
   * 路径复用单次解码及候选并集，未知方法不匹配有限方法列表。
   *
   * @param PathPolicyMatcher $matcher 当前请求匹配器
   * @param string $method 原始 HTTP 方法
   * @return bool 路径及方法均匹配
   */
  public function matches(PathPolicyMatcher $matcher, string $method): bool
  {
    return ($this->methods === [] || in_array(strtoupper($method), $this->methods, true))
      && array_any($this->paths, $matcher->matches(...));
  }
}
