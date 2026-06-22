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
use Viswoole\Router\ApiDoc\ParamSourceInterface\QueryParamInterface;

/**
 * GET 查询参数自动注入属性
 *
 * 标注到控制器参数或属性上，框架自动从 GET 查询参数中注入值，
 * 不允许为空时缺失参数将抛出验证异常。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectGet implements QueryParamInterface
{
  use ValidateNull;

  /**
   * 从 GET 查询参数中获取值并校验空值
   *
   * @param string $name 参数名
   * @param mixed $value 默认值
   * @param bool $allowNull 是否允许为 null
   * @return mixed 参数值
   */
  #[Override] public function inject(string $name, mixed $value, bool $allowNull): mixed
  {
    $value = Request::get($name, $value);
    return $this->validateEmpty($value, $allowNull, "(GET)请求参数{$name}不能为空");
  }
}
