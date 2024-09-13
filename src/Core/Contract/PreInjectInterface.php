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

namespace Viswoole\Core\Contract;

use Viswoole\Core\Exception\ValidateException;

/**
 * 前置注入参数注解基类
 *
 * 用途：如果要在容器注入参数时对某个参数实现自定义注入，则需要继承该类，并实现inject方法。
 */
interface PreInjectInterface
{
  /**
   * 注入参数值
   *
   * @param string $name 当前正在注入的参数名称
   * @param mixed $value 默认值,如果没有默认值，则为null
   * @return mixed 返回要注入的值
   * @throws ValidateException 如果希望停止注入，则抛出一个ValidateException异常，当然你也可以抛出其他任何异常来终止注入。
   */
  public function inject(string $name, mixed $value, bool $allowEmpty): mixed;
}
