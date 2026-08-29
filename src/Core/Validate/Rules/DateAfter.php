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
 * 日期晚于验证规则，校验日期字符串是否晚于指定的时间基准点
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateAfter extends BaseValidateRule
{
  /**
   * @param string|int $datetime 时间基准点，支持 Y-m-d H:i:s 格式字符串、时间戳，以及 "+N"/"-N" 偏移语法（相对当前时间偏移 N 秒）
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    public string|int $datetime,
    string            $message = ''
  )
  {
    if (is_string($this->datetime)) {
      if (str_starts_with($this->datetime, '+')) {
        $time = time() + intval(substr($this->datetime, 1));
        $this->datetime = date('Y-m-d H:i:s', $time);
      } elseif (str_starts_with($this->datetime, '-')) {
        $time = time() - intval(substr($this->datetime, 1));
        $this->datetime = date('Y-m-d H:i:s', $time);
      }
    }
    parent::__construct($message);
  }

  /**
   * 校验日期是否晚于基准时间点
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value)) $this->error('{:name} 必须为有效的日期字符串');
    // 修复: $this->datetime 可能为 int(时间戳)，strtotime() 在 strict_types 下不接受 int
    $threshold = is_int($this->datetime) ? $this->datetime : strtotime($this->datetime);
    if (strtotime($value) <= $threshold) {
      $this->error("{:name} 必须在 $this->datetime 之后");
    }
    return $value;
  }
}
