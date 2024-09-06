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
 * 区间验证,对数值类型数据进行验证。
 * 大于等于 起始值 或小于等于 结束值
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Between extends RuleAbstract
{
  /**
   * @param int|float $start 起始值
   * @param int|float $end 结束值
   * @param string $message
   */
  public function __construct(
    public int|float $start,
    public int|float $end,
    string           $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('必须为数值类型');
    $value = is_float($this->start) ? floatval($value) : intval($value);
    if ($value >= $this->start && $value <= $this->end) return $value;
    $this->error("必须介于 $this->start - $this->end 之间");
  }
}
