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

namespace Viswoole\HttpServer\Validate;

use Attribute;
use Viswoole\Core\Validate\Rules\RuleAbstract;
use Viswoole\HttpServer\AutoInject\File;
use Viswoole\HttpServer\Facade\Request;
use Viswoole\HttpServer\Message\UploadedFile;

/**
 * HTTP上传文件校验
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class FileRule extends RuleAbstract
{
  /**
   * @param string $name 表单name属性
   * @param string $fileMime 文件媒体类型
   * @param int $maxSize 文件最大长度
   * @param string $message
   */
  public function __construct(
    public readonly string $name,
    public readonly string $fileMime = '*',
    public readonly int    $maxSize = PHP_INT_MAX,
    string                 $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function validate(mixed $value): mixed
  {
    $upload = Request::files();
    if (!isset($upload[$this->name])) {
      $this->error("必须上传 $this->name 文件");
    }
    $file = $upload[$this->name] ?? [];
    $file = $file instanceof UploadedFile ? [$file] : $file;
    /**
     * @var $item UploadedFile
     */
    foreach ($file as $item) {
      if ($this->fileMime !== '*' && $item->getClientMediaType() !== $this->fileMime) {
        $this->error("$this->name 文件的类型必须为 $this->fileMime");
      }
      if ($item->getSize() > $this->maxSize) {
        $this->error("$this->name 文件大小不能超过 $this->maxSize 字节");
      }
    }
    if (!$value instanceof File) $value = new File();
    $value->list = $file;
    $value->name = $this->name;
    return $value;
  }
}
