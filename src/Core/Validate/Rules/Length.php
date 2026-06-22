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
 * 长度验证规则，校验字符串或数组的长度是否在指定范围内
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Length extends BaseValidateRule
{
  /**
   * @param int $min 最小长度（含）
   * @param int|null $max 最大长度（含），为 null 时不限制上界
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    public int  $min,
    public ?int $max = null,
    string      $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 校验字符串或数组长度是否在指定范围内
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (is_string($value)) {
      // 修复: 先 trim 再计算长度，并返回 trim 后的值，保持校验与返回值一致
      $value = trim($value);
      $len = mb_strlen($value);
    } elseif (is_array($value)) {
      $len = count($value);
    } else {
      $this->error('长度不符合要求');
    }
    if ($len < $this->min || ($this->max !== null && $len > $this->max)) {
      $message = is_null($this->max)
        ? "长度必须为$this->min"
        : "长度必须在 $this->min 到 $this->max 之间";
      $this->error($message);
    }
    return $value;
  }
}
