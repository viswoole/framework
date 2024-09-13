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
use Viswoole\HttpServer\Message\UploadedFile;
use Viswoole\Router\ApiDoc\Body\FileParamInterface;

/**
 * 注入上传的文件
 *
 * 多个文件为数组`UploadedFile[]`, 单个文件为`UploadedFile`对象
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectFile implements FileParamInterface
{
  use ValidateEmpty;

  /**
   * @inheritDoc
   */
  #[Override] public function inject(
    string $name,
    mixed  $value,
    bool   $allowEmpty
  ): array|null|UploadedFile
  {
    $value = Request::files($name) ?? $value;
    return $this->validateEmpty($value, $allowEmpty, "必须上传{$name}文件");
  }
}
