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
 * 日期早于验证规则，校验日期字符串是否早于指定的时间基准点
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateBefore extends DateAfter
{
  /**
   * 校验日期是否早于基准时间点
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value)) $this->error('{:name} 必须为有效的日期字符串');
    // 修复: $this->datetime 可能为 int(时间戳)，strtotime() 在 strict_types 下不接受 int
    $threshold = is_int($this->datetime) ? $this->datetime : strtotime($this->datetime);
    if (strtotime($value) >= $threshold) {
      $this->error("{:name} 必须在 $this->datetime 之前");
    }
    return $value;
  }
}
