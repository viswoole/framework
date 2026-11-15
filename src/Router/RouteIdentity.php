<?php

declare(strict_types=1);

/* 职责：表示已注册接口的稳定身份，不包含实际路径参数或请求 Host。 */

namespace Viswoole\Router;

use InvalidArgumentException;

/** 使用目录提供的身份；规范模板由路由注册器生成，不在此处再次解码。 */
final readonly class RouteIdentity
{
  private string $index;
  public string $method;
  public string $template;
  /** @var list<string> */
  public array $domainConstraints;

  /**
   * 创建精确接口身份
   * @param string $method 具体 HTTP 方法
   * @param string $template 已规范化注册模板
   * @param list<string> $domainConstraints 注册路由的域名约束
   * @throws InvalidArgumentException 身份不是有界有效值
   */
  public function __construct(string $method, string $template, array $domainConstraints)
  {
    if (preg_match('/^[A-Z]{1,32}$/D', $method) !== 1 || !str_starts_with($template, '/')
      || preg_match('//u', $template) !== 1 || strlen($template) > 2048) {
      throw new InvalidArgumentException('接口身份需要具体大写方法及最长 2048 字节的注册模板');
    }
    if ($domainConstraints === [] || !array_all(
      $domainConstraints,
      static fn (mixed $domain): bool => is_string($domain) && $domain !== '' && preg_match('//u', $domain) === 1
    )) {
      throw new InvalidArgumentException('接口域名约束必须为非空字符串集合');
    }
    // 域名按现有路由的精确比较语义保留大小写，只对集合排序及去重。
    $domains = in_array('*', $domainConstraints, true) ? ['*'] : array_values(array_unique($domainConstraints));
    sort($domains, SORT_STRING);
    $this->method = $method;
    $this->template = $template;
    $this->domainConstraints = $domains;
    $encoded = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $this->index = hash('sha256', $encoded);
    if (strlen($encoded) > 2048) {
      throw new InvalidArgumentException('接口身份编码超过 2048 字节');
    }
  }

  /** @return array{method:string,template:string,domain_constraints:list<string>} 序列化身份 */
  public function toArray(): array
  {
    return ['method' => $this->method, 'template' => $this->template, 'domain_constraints' => $this->domainConstraints];
  }

  /** @return string 精确身份的索引键 */
  public function key(): string
  {
    return $this->index;
  }
}
