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
 * 验证数值是否大于或等于$max
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Max extends RuleAbstract
{
  /**
   * @param int $max 最大值
   * @param string $message
   */
  public function __construct(
    public int $max,
    string     $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function validate(mixed $value): int|float
  {
    if (!is_numeric($value)) $this->error('必须为数值类型');
    $value = is_float($this->max) ? floatval($value) : intval($value);
    if ($value > $this->max) {
      $this->error("必须小于或等于 $this->max");
    }
    return $value;
  }
}
