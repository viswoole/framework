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
   * 校验数值是否在闭区间范围内，返回数值化后的值
   *
   * 数值化不经边界字面量类型决定（int 保持 int、float 保持 float、
   * 数字字符串转为数值）——修复 Between(0, 100)（整型字面量边界）时
   * float 入参 0.5 被 intval 截断为 0 的问题
   */
  #[Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('{:name} 必须为数值类型');
    $num = $value + 0;
    if ($num >= $this->start && $num <= $this->end) return $num;
    // 修复: 错误信息包含实际值，便于调试定位
    $this->error("{:name} 值 $num 必须介于 $this->start - $this->end 之间");
  }
}
