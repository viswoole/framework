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
 * 排除范围验证规则，校验值是否不在指定的禁止列表中
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotInArray extends BaseValidateRule
{
  /**
   * @param array $haystack 禁止值列表
   * @param bool $strict 是否使用严格模式比较（===）
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    public array $haystack,
    public bool  $strict,
    string       $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 校验值是否不在禁止列表中
   */
  #[Override] public function validate(mixed $value): mixed
  {
    $valid = in_array($value, $this->haystack, $this->strict);
    if ($valid) $this->error('{:name} 不能是' . implode('、', $this->haystack));
    return $value;
  }
}
