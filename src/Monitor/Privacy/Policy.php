<?php

declare(strict_types=1);

/* 职责：保存全局默认及编译规则，按明确作用域合并保护，不执行采集。 */

namespace Viswoole\Monitor\Privacy;

use InvalidArgumentException;
use Viswoole\Monitor\PathPolicyMatcher;

/** 全局默认与所有命中规则共同生效，禁止重新开启已关闭的采集。 */
final readonly class Policy
{
  /** @var array<string,Settings> 明确作用域的默认设置 */
  private array $defaults;
  /** @var list<Rule> 启动期验证的追加规则 */
  private array $rules;

  /**
   * 编译隐私策略
   *
   * 校验在启动时完成，不将配置错误延迟到请求内。
   *
   * @param mixed $value privacy 配置
   * @param bool $isCaseSensitive 路由大小写策略
   */
  public function __construct(mixed $value = [], bool $isCaseSensitive = false)
  {
    $config = Configuration::object($value, ['defaults', 'rules'], 'monitor.privacy');
    $this->defaults = Configuration::groups(array_key_exists('defaults', $config) ? $config['defaults'] : [], 'monitor.privacy.defaults');
    $rules = array_key_exists('rules', $config) ? $config['rules'] : [];
    if (!is_array($rules) || !array_is_list($rules)) {
      throw new InvalidArgumentException('monitor.privacy.rules 必须为规则列表');
    }
    $this->rules = array_map(static fn (mixed $rule): Rule => new Rule($rule, $isCaseSensitive), $rules);
  }

  /**
   * 解析一个请求分组的有效设置
   *
   * 所有规则取保护并集，不依赖数组顺序。
   *
   * @param Context $context 请求身份及作用域
   * @param PathPolicyMatcher $matcher 已构建的单次解码路径匹配器
   * @return Settings 不可变的有效保护设置
   */
  public function resolve(Context $context, PathPolicyMatcher $matcher): Settings
  {
    $settings = $this->defaults[$context->target->value] ?? new Settings();
    foreach ($this->rules as $rule) {
      if (isset($rule->groups[$context->target->value]) && $rule->matches($matcher, $context->method)) {
        $settings = $settings->merge($rule->groups[$context->target->value]);
      }
    }
    return $settings;
  }
}
