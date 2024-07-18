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

declare (strict_types=1);

namespace Viswoole\Core\Validate\Rules;

use Attribute;

/**
 * 正则表达式校验
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Regex extends RuleAbstract
{
  /**
   * @param string $pattern 正则表达式
   * @param string $message 校验失败信息
   */
  public function __construct(
    private readonly string $pattern,
    string                  $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value) || !preg_match($this->pattern, $value)) {
      $this->error("必须符合 $this->pattern 匹配规则");
    }
    return $value;
  }
}
