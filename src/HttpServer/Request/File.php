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

namespace Viswoole\HttpServer\Request;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Override;
use RuntimeException;
use Traversable;
use Viswoole\HttpServer\Message\FileStream;
use Viswoole\HttpServer\Message\UploadedFile;

/**
 * 请求上传的文件注入
 *
 * 必须配合\Viswoole\HttpServer\AutoInject\InjectFile 注解使用。
 *
 * @method FileStream getStream() 获取流（固定获取上传的第一个文件）
 * @method bool isMoved() 判断文件是否已移动（固定判断上传的第一个文件）
 * @method int getError() 获取状态码，0为正常（固定获取上传的第一个文件）
 * @method int getSize() 获取文件大小（固定获取上传的第一个文件）
 * @method string getClientFilename() 获取文件名称（固定获取上传的第一个文件）
 * @method string getClientMediaType() 获取媒体类型（固定获取上传的第一个文件）
 */
class File implements ArrayAccess, IteratorAggregate, Countable
{
  /**
   * @var UploadedFile[] 文件对象|列表
   */
  private array $file;

  /**
   * 判断是否上传了多个文件
   *
   * @access public
   * @return bool
   */
  public function isMulti(): bool
  {
    return count($this->file) > 1;
  }

  /**
   * 转发方法
   *
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public function __call(string $name, array $arguments)
  {
    return $this->file[0]->{$name}(...$arguments);
  }

  /**
   * 判断是否上传了多个文件
   *
   * @return int
   */
  public function count(): int
  {
    return count($this->file);
  }

  /**
   * 注入上传文件
   *
   * 该方法由 \Viswoole\HttpServer\AutoInject\InjectFile 调用，无需手动注入。
   *
   * @param UploadedFile[] $file
   * @return void
   */
  public function inject(array $file): void
  {
    $this->file = $file;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return isset($this->file[$offset]);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    return $this->file[$offset] ?? null;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    throw new RuntimeException('File is read-only');
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    unset($this->file[$offset]);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getIterator(): Traversable
  {
    return new ArrayIterator($this->file);
  }
}
