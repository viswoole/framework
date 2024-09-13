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
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate;
use Viswoole\Core\Validate\BaseValidateRule;
use Viswoole\Core\Validate\Type;

/**
 * 数组元素验证器
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayItem extends BaseValidateRule
{
  /**
   * @param Type[]|Type $types 需要核验的类型，多个类型请用数组表达
   * @param string $message 错误信息
   */
  public function __construct(
    protected array|Type $types,
    string               $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): array
  {
    if (!is_array($value)) $this->error('必须为数组');
    $array = [];
    foreach ($value as $item) {
      try {
        Validate::check($item, $this->types);
      } catch (ValidateException $e) {
        $this->error($e->getMessage());
      }
    }
    return $array;
  }
}
