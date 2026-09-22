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
 * 最小值验证规则，校验数值是否不低于指定下界
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Min extends BaseValidateRule
{
  /**
   * @param int|float $min 允许的最小值（含）
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    public int|float $min,
    string           $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 校验数值是否不低于最小值（数值化策略与 Between 一致）
   */
  #[Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('{:name} 必须为数值类型');
    $num = $value + 0;
    if ($num < $this->min) {
      $this->error("{:name} 必须大于或等于 $this->min");
    }
    return $num;
  }
}
