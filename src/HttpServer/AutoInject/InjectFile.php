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
use Viswoole\Core\App;
use Viswoole\Core\Validate\Rules\RuleAbstract;
use Viswoole\HttpServer\Facade\Request;
use Viswoole\HttpServer\Message\UploadedFile;
use Viswoole\HttpServer\Request\File;

/**
 * HTTP上传文件注入与校验
 *
 * 与\Viswoole\HttpServer\Request\File配合使用
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectFile extends RuleAbstract
{
  /**
   * @param string $fileMime 文件媒体类型
   * @param int $maxSize 文件最大长度,单位字节，小于等于0为不限制长度
   * @param int $count 上传文件数量，小于等于0为不限制文件数量
   * @param string $name form-data参数名，默认对应参数名
   * @param string $message
   */
  public function __construct(
    public readonly string $fileMime = '*',
    public readonly int    $maxSize = 0,
    public readonly int    $count = 0,
    private string         $name = '',
    string                 $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value, string $key = ''): mixed
  {
    $this->name = empty($this->name) ? $key : $this->name;
    $upload = Request::files($this->name);
    if (empty($upload)) {
      if (is_null($value)) return null;
      $this->error("必须上传 $this->name 文件");
    }
    if ($this->count > 0 && count($upload) !== $this->count) {
      $this->error("必须上传 $this->count 个 $this->name 文件");
    }
    foreach ($upload as $item) $this->checkFile($item);
    if (!$value instanceof File) {
      $value = App::factory()->invokeClass(File::class);
    }
    $value->inject($upload);
    return $value;
  }

  /**
   * 验证文件
   *
   * @param UploadedFile $file
   * @return void
   */
  private function checkFile(UploadedFile $file): void
  {
    if ($this->fileMime !== '*' && $file->getClientMediaType() !== $this->fileMime) {
      $this->error("$this->name 文件的类型必须为 $this->fileMime");
    }
    if ($this->maxSize > 0 && $file->getSize() > $this->maxSize) {
      $this->error("$this->name 文件大小不能超过 $this->maxSize 字节");
    }
  }
}
