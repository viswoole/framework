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

namespace Viswoole\HttpServer\AutoInject;

use Viswoole\Core\Exception\ValidateException;

/**
 * 参数空值校验复用 Trait
 *
 * 为 Inject* 注解类提供统一的空值校验逻辑，
 * 不允许为空时抛出 ValidateException。
 */
trait ValidateNull
{
  /**
   * 校验注入值是否为空，不允许为空时抛出验证异常
   *
   * @param mixed $value 已获取的参数值
   * @param bool $allowNull 是否允许为 null
   * @param string $message 为空时的错误提示
   * @return mixed 原值
   * @throws ValidateException 不允许为空且值为 null 时抛出
   */
  protected function validateEmpty(mixed $value, bool $allowNull, string $message): mixed
  {
    if (!$allowNull && is_null($value)) {
      throw new ValidateException($message);
    }
    return $value;
  }
}
