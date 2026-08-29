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
 * 区间验证规则，校验数值是否在指定的闭区间 [start, end] 范围内
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Between extends BaseValidateRule
{
  /**
   * @param int|float $start 区间下界（含）
   * @param int|float $end 区间上界（含）
   * @param string $message 校验失败提示信息
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
   * 校验数值是否在闭区间范围内，返回类型转换后的值
   */
  #[Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('{:name} 必须为数值类型');
    // 修复: 类型转换应同时参考 start 和 end 的类型，任一为 float 则按 float 处理
    $value = (is_float($this->start) || is_float($this->end)) ? floatval($value) : intval($value);
    if ($value >= $this->start && $value <= $this->end) return $value;
    // 修复: 错误信息包含实际值，便于调试定位
    $this->error("{:name} 值 $value 必须介于 $this->start - $this->end 之间");
  }
}
