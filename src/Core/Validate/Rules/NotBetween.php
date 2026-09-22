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

/**
 * 排除区间验证规则，校验数值是否不在指定的闭区间 [start, end] 范围内
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotBetween extends Between
{
  /**
   * 校验数值是否不在闭区间范围内（数值化策略与 Between 一致）
   */
  #[Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('{:name} 必须为数值类型');
    $num = $value + 0;
    if ($num >= $this->start && $num <= $this->end) {
      $this->error("{:name} 值 $num 必须不在 $this->start - $this->end 之间");
    }
    return $num;
  }
}
