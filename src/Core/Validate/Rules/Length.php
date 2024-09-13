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
 * 长度验证，仅对基本类型为 string、array生效。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Length extends BaseValidateRule
{
  /**
   * @param int $min 最小长度
   * @param int|null $max 最大长度，为null则不限制
   * @param string $message
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
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (is_string($value)) {
      $len = mb_strlen(trim($value));
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
