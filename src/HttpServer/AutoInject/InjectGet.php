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
use Viswoole\Core\Exception\ValidateException;
use Viswoole\HttpServer\Facade\Request;
use Viswoole\Router\ApiDoc\Body\GetParamInterface;

/**
 * 注入GET请求参数
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectGet implements GetParamInterface
{

  /**
   * @inheritDoc
   */
  #[Override] public function inject(string $name, mixed $value, bool $allowEmpty): mixed
  {
    $value = Request::get($name, $value);
    if (!$allowEmpty && empty($value)) {
      throw new ValidateException('(GET)请求参数' . $name . '不能为空');
    }
    return $value;
  }
}
