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
 * 验证数值是否大于或等于$max
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Max extends BaseValidateRule
{
  /**
   * @param int|float $max 最大值
   * @param string $message
   */
  public function __construct(
    public int|float $max,
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
    // 修复: $max 类型已改为 int|float，根据 $max 实际类型决定转换方式，消除 is_float 死代码
    $value = is_float($this->max) ? floatval($value) : intval($value);
    if ($value > $this->max) {
      $this->error("必须小于或等于 $this->max");
    }
    return $value;
  }
}
