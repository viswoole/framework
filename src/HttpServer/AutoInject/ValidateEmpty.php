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
 * 验证是否为空
 */
trait ValidateEmpty
{
  /**
   * 验证是否为空
   *
   * @param mixed $value 处理好的值
   * @param bool $allowEmpty 是否允许为空
   * @param string $message 如果为空的提示信息
   * @return mixed
   */
  protected function validateEmpty(mixed $value, bool $allowEmpty, string $message): mixed
  {
    if (!$allowEmpty && empty($value)) {
      throw new ValidateException($message);
    }
    return $value;
  }
}
