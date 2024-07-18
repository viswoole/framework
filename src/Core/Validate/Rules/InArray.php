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
 * 范围验证器
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InArray extends RuleAbstract
{
  /**
   * @param array $haystack 范围数组
   * @param bool $strict 严格检测
   * @param string $message 不传则会使用默认的错误提示信息
   */
  public function __construct(
    public array $haystack,
    public bool  $strict,
    string       $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function validate(mixed $value): mixed
  {
    $valid = in_array($value, $this->haystack, $this->strict);
    if (!$valid) $this->error('必须是' . implode('、', $this->haystack) . '之一');
    return $value;
  }
}
