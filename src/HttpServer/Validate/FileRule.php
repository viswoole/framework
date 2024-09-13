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
use Override;
use Viswoole\Core\Validate\BaseValidateRule;
use Viswoole\HttpServer\Message\UploadedFile;

/**
 * HTTP上传文件校验
 *
 * 与Viswoole\HttpServer\AutoInject\File注解配合使用
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class FileRule extends BaseValidateRule
{
  /**
   * @param string $fileMime 文件媒体类型，多个用`|`分割
   * @param int $maxSize 文件最大长度,单位字节，小于等于0为不限制长度
   * @param int $count 上传文件数量，小于等于0为不限制文件数量
   * @param string $message 错误提示，如果不为空则会覆盖掉默认提示
   */
  public function __construct(
    public readonly string $fileMime = '*',
    public readonly int    $maxSize = 0,
    public readonly int    $count = 0,
    string                 $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value, string $name = ''): mixed
  {
    if (empty($value)) {
      if (is_null($value)) return null;
      $this->error("必须上传 $name 文件");
    }
    if ($this->count > 0 && count($value) !== $this->count) {
      $this->error("必须上传 $this->count 个 $name 文件");
    }
    if (is_array($value)) {
      foreach ($value as $item) $this->checkFile($item, $name);
    } else {
      $this->checkFile($value, $name);
    }
    return $value;
  }

  /**
   * 验证文件
   *
   * @param UploadedFile $file
   * @param string $name
   * @return void
   */
  private function checkFile(UploadedFile $file, string $name): void
  {
    if ($this->fileMime !== '*') {
      $type = $file->getClientMediaType();
      $types = explode('|', $this->fileMime);
      array_walk($types, function (&$item) {
        $item = strtolower(trim($item));
      });
      if (!in_array(strtolower($type), $types)) return;
      $this->error("$name 文件的类型必须为 $this->fileMime");
    }
    if ($this->maxSize > 0 && $file->getSize() > $this->maxSize) {
      $this->error("$name 文件大小不能超过 $this->maxSize 字节");
    }
  }
}
