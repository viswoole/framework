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
use Viswoole\Router\ApiDoc\Body\PostParamInterface;

/**
 * 用于注入POST请求参数
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectPost implements PostParamInterface
{
  /**
   * @inheritDoc
   */
  #[Override] public function inject(string $name, mixed $value): mixed
  {
    return Request::post($name, $value);
  }
}
