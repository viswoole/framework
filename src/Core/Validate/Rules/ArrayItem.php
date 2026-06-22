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
 * 数组元素验证规则，对数组中每个元素递归执行类型校验
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ArrayItem extends BaseValidateRule
{
  /**
   * @param Type[]|Type $types 数组元素需要满足的类型约束，多个类型用数组表示
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    protected array|Type $types,
    string               $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 逐元素执行类型校验，返回校验后的数组
   */
  #[Override] public function validate(mixed $value): array
  {
    if (!is_array($value)) $this->error('必须为数组');
    $array = [];
    foreach ($value as $item) {
      try {
        // 修复: 原代码未将校验结果存入数组，导致始终返回空数组
        $array[] = Validate::check($item, $this->types);
      } catch (ValidateException $e) {
        $this->error($e->getMessage());
      }
    }
    return $array;
  }
}
