<?php

declare(strict_types=1);

/* 职责：表示单个分组的有效采集与脱敏设置，不保留原始配置引用。 */

namespace Viswoole\Monitor\Privacy;

/** 合并后的只读保护设置。 */
final readonly class Settings
{
  /** @var array<string,true> 规范化字段查找表 */
  public array $fields;

  /**
   * 构建分组保护设置
   *
   * 内置字段始终参与保护；空的用户列表不能撤销内置保护。
   *
   * @param bool $canCapture 是否允许采集分组
   * @param list<string> $fields 自定义完整字段名
   */
  public function __construct(public bool $canCapture = true, array $fields = [])
  {
    $this->fields = array_fill_keys(array_map(Fields::normalize(...), array_merge(Fields::BUILT_IN, $fields)), true);
  }

  /**
   * 合并追加保护
   *
   * 关闭采集具有否决权，字段取并集，规则顺序不改变结果。
   *
   * @param self $other 另一条已验证设置
   * @return self 合并后的独立设置
   */
  public function merge(self $other): self
  {
    return new self($this->canCapture && $other->canCapture, array_keys($this->fields + $other->fields));
  }
}
