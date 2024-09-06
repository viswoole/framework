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
use DateTime;
use Override;

/**
 * 验证日期格式
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateFormat extends RuleAbstract
{
  /**
   * @param string $format 日期格式(可选值参考date())
   * @param string $message
   */
  public function __construct(
    public string $format,
    string        $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value)) $this->error('必须为有效的日期字符串');
    $date = DateTime::createFromFormat($this->format, $value);
    $valid = $date && $date->format($this->format) === $value;
    if (!$valid) $this->error("必须为 $this->format 格式的日期");
    return $value;
  }
}
