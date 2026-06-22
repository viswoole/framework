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

use Attribute;
use Override;
use Viswoole\HttpServer\Facade\Request;
use Viswoole\Router\ApiDoc\ParamSourceInterface\HeaderParamInterface;

/**
 * 请求标头自动注入属性
 *
 * 标注到控制器参数或属性上，框架自动从请求标头中注入值，
 * 不允许为空时缺失标头将抛出验证异常。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectHeader implements HeaderParamInterface
{
  use ValidateNull;

  /**
   * 从请求标头中获取值并校验空值
   *
   * @param string $name 标头名称
   * @param mixed $value 默认值
   * @param bool $allowNull 是否允许为 null
   * @return string|null 标头值
   */
  #[Override] public function inject(string $name, mixed $value, bool $allowNull): string|null
  {
    $value = Request::getHeader($name, $value);
    return $this->validateEmpty($value, $allowNull, "请求头{$name}不能为空");
  }
}
