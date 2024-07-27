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

use Viswoole\HttpServer\Facade\Request;
use ViSwoole\HttpServer\Message\UploadedFile;

/**
 * 用于自动注入
 */
class File
{
  public string $name;
  /**
   * @var UploadedFile[] 文件对象列表
   */
  public array $list;

  /**
   * 获取上传的所有文件
   *
   * @return array<string,UploadedFile|UploadedFile[]>|null
   */
  public function all(): array|null
  {
    return Request::files();
  }
}
