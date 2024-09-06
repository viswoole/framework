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
 * 日期验证，验证日期必须大于datetime
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateAfter extends RuleAbstract
{
  /**
   * @param string|int $datetime datetime,接收Y-m-d H:i:s格式的日期时间字符串，支持传入+N或-N表示从当前时间偏移N秒
   * @param string $message
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
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value)) $this->error('必须为有效的日期字符串');
    if (strtotime($value) <= strtotime($this->datetime)) {
      $this->error("必须在 $this->datetime 之后");
    }
    return $value;
  }
}
