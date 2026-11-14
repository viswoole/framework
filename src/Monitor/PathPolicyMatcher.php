<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

use Viswoole\Core\Common\PathNormalizer;

/**
 * 采集前的路径保护匹配；不解析路由，不改变分发行为。
 * 完整路径与所有路由候选取保护并集，避免匹配完成前已采集敏感数据。
 */
final readonly class PathPolicyMatcher
{
  /** @var list<array{string,string}> 已归一化的路由候选 */
  private array $candidates;

  /**
   * 仅对原始请求路径解码一次，配置模式由调用方预先归一化。
   * @param string $path 原始请求路径
   * @param bool $isCaseSensitive 路由大小写策略
   */
  public function __construct(string $path, bool $isCaseSensitive)
  {
    $this->candidates = PathNormalizer::candidates(PathNormalizer::forMatching($path, $isCaseSensitive));
  }

  /**
   * 判断规则是否保护任一路由候选。
   * @param string $pattern 已归一化的配置模式
   * @return bool 任一候选命中时返回 true
   */
  public function matches(string $pattern): bool
  {
    // 不对候选再次解码，防止双重编码或编码斜线改变资源边界。
    return array_any($this->candidates, static fn (array $candidate): bool => fnmatch($pattern, $candidate[0]));
  }
}
