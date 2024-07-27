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

namespace Viswoole\HttpServer\Message;


use InvalidArgumentException;
use RuntimeException;

/**
 * 客户端上传的文件
 */
class UploadedFile
{

  /**
   * @var string 缓存路径
   */
  public readonly string $tmp_path;
  /**
   * @var FileStream 资源流
   */
  protected FileStream $stream;
  /**
   * @var string 媒体类型
   */
  protected string $type;
  /**
   * @var int 文件大小
   */
  protected int $size;
  /**
   * @var string 文件名称
   */
  protected string $name;
  /**
   * @var int 状态码
   */
  protected int $error;
  /**
   * @var bool 是否移动
   */
  protected bool $moved = false;

  /**
   * @param string $type 媒体类型
   * @param string $name 文件名称
   * @param int $size 文件大小
   * @param string $tmp_name 缓存路径
   * @param int $error 状态码
   */
  public function __construct(string $type, string $name, int $size, string $tmp_name, int $error)
  {
    $this->type = $type;
    $this->name = $name;
    $this->size = $size;
    $this->tmp_path = $tmp_name;
    $this->error = $error;
  }

  /**
   * 获取文件流
   *
   * @access public
   * @return FileStream
   */
  public function getStream(): FileStream
  {
    if (!isset($this->stream)) {
      $this->stream = new FileStream($this->tmp_path); // 以二进制只读模式打开文件流
    }
    return $this->stream;
  }

  /**
   * 移动文件到指定目录
   *
   * @access public
   * @param string $targetPath 目标路径需要包含文件名
   * @return void
   */
  public function moveTo(string $targetPath): void
  {
    $this->validateActive();

    if (false === $this->isStringNotEmpty($targetPath)) {
      throw new InvalidArgumentException(
        'Invalid path provided for move operation; must be a non-empty string'
      );
    }
    $dir = dirname($targetPath);

    if (false === is_dir($dir) && false === mkdir($dir, 0777, true)) {
      throw new RuntimeException(
        sprintf(
          'Uploaded file could not be moved to %s because it is not possible to create that directory',
          $dir
        )
      );
    }
    $name = basename($targetPath);
    $targetPath = "$dir/$name";
    $this->moved = PHP_SAPI === 'cli'
      ? rename($this->tmp_path, $targetPath)
      : move_uploaded_file($this->tmp_path, $targetPath);
    if (false === $this->moved) {
      throw new RuntimeException(
        sprintf('Uploaded file could not be moved to %s', $targetPath)
      );
    }
  }

  /**
   * @throws RuntimeException 如果被移动或不正常
   */
  private function validateActive(): void
  {
    if (false === $this->isOk()) {
      throw new RuntimeException('Cannot retrieve stream due to upload error');
    }

    if ($this->isMoved()) {
      throw new RuntimeException('Cannot retrieve stream after it has already been moved');
    }
  }

  /**
   * 如果没有上传错误，则返回 true
   * @return bool
   */
  private function isOk(): bool
  {
    return $this->error === UPLOAD_ERR_OK;
  }

  /**
   * 判断文件是否已移动
   *
   * @return bool
   */
  public function isMoved(): bool
  {
    return $this->moved;
  }

  private function isStringNotEmpty($param): bool
  {
    return is_string($param) && false === empty($param);
  }

  /**
   * 获取错误码 0为正常
   *
   * @access public
   * @return int
   */
  public function getError(): int
  {
    return $this->error;
  }

  /**
   * 获取文件大小
   *
   * @access public
   * @return int|null
   */
  public function getSize(): ?int
  {
    return $this->size;
  }

  /**
   * 获取文件名
   *
   * @access public
   * @return string|null
   */
  public function getClientFilename(): ?string
  {
    return $this->name;
  }

  /**
   * 获取媒体类型
   *
   * @access public
   * @return string|null
   */
  public function getClientMediaType(): ?string
  {
    return $this->type;
  }

}
