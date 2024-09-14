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
 * 注入请求标头
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectHeader implements HeaderParamInterface
{
  use ValidateNull;

  /**
   * 注入请求头
   *
   * @param string $name 标头
   * @param mixed $value 默认值
   * @param bool $allowNull 是否允许为空
   * @inheritDoc
   */
  #[Override] public function inject(string $name, mixed $value, bool $allowNull): string|null
  {
    $value = Request::getHeader($name, $value);
    return $this->validateEmpty($value, $allowNull, "请求头{$name}不能为空");
  }
}
