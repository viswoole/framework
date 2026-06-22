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
use Viswoole\Router\ApiDoc\ParamSourceInterface\FileParamInterface;

/**
 * 上传文件自动注入属性
 *
 * 标注到控制器参数或属性上，框架自动从上传文件中注入值，
 * 单文件返回 UploadedFile，多文件返回 UploadedFile[]，
 * 不允许为空时缺失文件将抛出验证异常。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectFile implements FileParamInterface
{
  use ValidateNull;

  /**
   * 从上传文件中获取值并校验空值
   *
   * @param string $name 表单字段名
   * @param mixed $value 默认值
   * @param bool $allowNull 是否允许为 null
   * @return array|UploadedFile|null 文件实例
   */
  #[Override] public function inject(
    string $name,
    mixed  $value,
    bool   $allowNull
  ): array|null|UploadedFile
  {
    $value = Request::files($name) ?? $value;
    return $this->validateEmpty($value, $allowNull, "必须上传{$name}文件");
  }
}
