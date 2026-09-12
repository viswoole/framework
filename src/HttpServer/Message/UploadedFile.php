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
 * 客户端上传文件的值对象
 *
 * 封装 Swoole 上传文件信息，提供文件流获取、文件移动、
 * 错误码查询等能力，移动后不可再获取流。
 */
class UploadedFile
{

  /**
   * @var string 临时文件路径
   */
  public readonly string $tmp_path;
  /**
   * @var FileStream|null 延迟初始化的文件流（首次访问时创建）
   */
  protected FileStream $stream;
  /**
   * @var string 客户端声明的 MIME 类型
   */
  protected string $type;
  /**
   * @var int 文件大小（字节）
   */
  protected int $size;
  /**
   * @var string 客户端原始文件名
   */
  protected string $name;
  /**
   * @var int 上传错误码，UPLOAD_ERR_OK 表示正常
   */
  protected int $error;
  /**
   * @var bool 文件是否已移动
   */
  protected bool $moved = false;

  /**
   * @param string $type 客户端声明的 MIME 类型
   * @param string $name 客户端原始文件名
   * @param int $size 文件大小（字节）
   * @param string $tmp_name 临时文件路径
   * @param int $error 上传错误码，UPLOAD_ERR_OK 表示正常
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
   * 获取文件流（延迟初始化）
   *
   * 首次调用时以二进制只读模式打开临时文件。
   * 上传存在错误或文件已被移动时禁止获取流：
   * 临时文件可能不存在或残留，避免异常消息泄露服务器临时路径
   *
   * @return FileStream 文件流实例
   */
  public function getStream(): FileStream
  {
    $this->validateActive();
    if (!isset($this->stream)) {
      $this->stream = new FileStream($this->tmp_path); // 以二进制只读模式打开文件流
    }
    return $this->stream;
  }

  /**
   * 将上传文件移动到指定路径
   *
   * CLI 模式使用 rename，其它模式使用 move_uploaded_file。
   * 目标目录不存在时自动递归创建。
   *
   * @param string $targetPath 目标路径（含文件名）
   * @throws InvalidArgumentException 路径为空时抛出
   * @throws RuntimeException 目录创建失败、文件移动失败或文件已移动/上传异常时抛出
   */
  public function moveTo(string $targetPath): void
  {
    $this->validateActive();

    if (empty($targetPath)) {
      throw new InvalidArgumentException(
        'Invalid path provided for move operation; must be a non-empty string'
      );
    }
    $dir = dirname($targetPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
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
   * 校验文件是否可操作（未移动且上传正常）
   *
   * @throws RuntimeException 文件已移动或上传异常时抛出
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
   * 判断上传是否无错误
   *
   * @return bool UPLOAD_ERR_OK 时返回 true
   */
  private function isOk(): bool
  {
    return $this->error === UPLOAD_ERR_OK;
  }

  /**
   * 判断文件是否已移动
   *
   * @return bool 已移动返回 true
   */
  public function isMoved(): bool
  {
    return $this->moved;
  }

  /**
   * 获取上传错误码
   *
   * @return int 错误码，UPLOAD_ERR_OK(0) 表示正常
   */
  public function getError(): int
  {
    return $this->error;
  }

  /**
   * 获取文件大小
   *
   * @return int|null 文件字节数
   */
  public function getSize(): ?int
  {
    return $this->size;
  }

  /**
   * 通过 finfo 检测文件内容的真实 MIME 类型
   *
   * 客户端声明的 Content-Type（getClientMediaType）可被任意伪造，
   * 安全校验场景（如上传白名单）应以内容检测结果为准
   *
   * @return string|null 真实 MIME 类型，临时文件不存在或检测失败时返回 null
   */
  public function getRealMimeType(): ?string
  {
    if (!is_file($this->tmp_path)) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) return null;
    // finfo 为对象实例，由 GC 自动释放（finfo_close 在 PHP 8.5 已弃用）
    $mime = finfo_file($finfo, $this->tmp_path);
    return $mime === false ? null : $mime;
  }

  /**
   * 获取客户端原始文件名
   *
   * @return string|null 文件名
   */
  public function getClientFilename(): ?string
  {
    return $this->name;
  }

  /**
   * 获取客户端声明的 MIME 类型
   *
   * @return string|null MIME 类型
   */
  public function getClientMediaType(): ?string
  {
    return $this->type;
  }

}
