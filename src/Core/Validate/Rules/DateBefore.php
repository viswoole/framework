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
 * 日期验证，验证日期必须小于datetime
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateBefore extends DateAfter
{
  /**
   * @inheritDoc
   */
  #[\Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value)) $this->error('必须为有效的日期字符串');
    if (strtotime($value) >= strtotime($this->datetime)) {
      $this->error("必须在 $this->datetime 之前");
    }
    return $value;
  }
}
