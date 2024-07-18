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

namespace Viswoole\Core\Validate;

use ReflectionAttribute;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\Rules\RuleAbstract;

/**
 * 扩展验证规则
 */
class Rules
{
  /**
   * 验证扩展类型
   *
   * @param ReflectionAttribute[]|RuleAbstract[] $rules 扩展验证规则
   * @param mixed $value 验证值
   * @return mixed
   * @throws ValidateException
   */
  public static function validate(array $rules, mixed $value): mixed
  {
    foreach ($rules as $attribute) {
      if ($attribute instanceof ReflectionAttribute) {
        $instance = $attribute->newInstance();
        // 判断是否为扩展规则
        if ($instance instanceof RuleAbstract) {
          $value = $instance->validate($value);
        }
      } elseif ($attribute instanceof RuleAbstract) {
        $value = $attribute->validate($value);
      }
    }
    return $value;
  }
}
