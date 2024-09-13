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
use Viswoole\Router\ApiDoc\Body\HeaderParamInterface;

/**
 * 注入请求头
 *
 * 多个文件为数组`UploadedFile[]`, 单个文件为`UploadedFile`对象
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectHeader implements HeaderParamInterface
{
  use ValidateEmpty;

  /**
   * 注入请求头
   *
   * @param string $name 标头
   * @param mixed $value 默认值
   * @param bool $allowEmpty 是否允许为空
   * @inheritDoc
   */
  #[Override] public function inject(string $name, mixed $value, bool $allowEmpty): string|null
  {
    $value = Request::getHeader($name, $value);
    return $this->validateEmpty($value, $allowEmpty, "请求头{$name}不能为空");
  }
}
