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
 * 前置注入契约接口，用于自定义容器参数注入逻辑
 *
 * 实现该接口后，容器在解析参数时会调用 inject 方法，
 * 允许对指定参数进行自定义注入或校验。
 */
interface PreInjectInterface
{
  /**
   * 自定义参数注入逻辑，容器解析参数时调用
   *
   * @param string $name 当前正在注入的参数名称
   * @param mixed $value 参数默认值，无默认值时为 null
   * @param bool $allowNull 参数是否允许为 null
   * @return mixed 返回实际注入的值
   * @throws ValidateException 需要终止注入时可抛出异常
   */
  public function inject(string $name, mixed $value, bool $allowNull): mixed;
}
