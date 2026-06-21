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
 * 验证数值是否不在某个区间
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NotBetween extends Between
{
  /**
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('必须为数值类型');
    // 修复: 类型转换应同时参考 start 和 end 的类型，任一为 float 则按 float 处理（与 Between 一致）
    $value = (is_float($this->start) || is_float($this->end)) ? floatval($value) : intval($value);
    if ($value >= $this->start && $value <= $this->end) {
      $this->error("值 $value 必须不在 $this->start - $this->end 之间");
    }
    return $value;
  }
}
