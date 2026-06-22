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
use Override;
use Viswoole\Core\Validate\BaseValidateRule;

/**
 * 正则表达式验证规则，校验字符串是否匹配指定正则模式
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Regex extends BaseValidateRule
{
  /**
   * @param string $pattern 正则表达式模式
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    private readonly string $pattern,
    string                  $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 校验字符串是否匹配正则模式
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value) || !preg_match($this->pattern, $value)) {
      $this->error("必须为匹配 $this->pattern 规则的字符串");
    }
    return $value;
  }
}
