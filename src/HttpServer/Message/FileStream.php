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

use RuntimeException;

class FileStream
{
  /**
   * @var resource 资源流
   */
  protected $stream;

  public function __construct(string $filePath, string $mode = 'r')
  {
    $stream = fopen($filePath, $mode);
    if (!$stream) throw new RuntimeException('无法打开文件流：' . $filePath);
    $this->stream = $stream;
  }

  /**
   * 创建流实例
   *
   * @access public
   * @param string $filePath 文件路径
   * @param string $mode 参考https://www.php.net/manual/zh/function.fopen.php
   * @return static
   */
  public static function create(string $filePath, string $mode = 'r'): static
  {
    return new static($filePath, $mode);
  }

  /**
   * 将整个流内容作为字符串返回
   *
   * @return string
   */
  public function __toString(): string
  {
    if (!is_resource($this->stream)) return '';
    // 回退指针
    rewind($this->stream);
    return stream_get_contents($this->stream);
  }

  /**
   * 从流中分离底层资源，返回分离的资源（如果有）
   *
   * @return resource|null Underlying PHP stream, if any
   */
  public function detach()
  {
    $stream = $this->stream;
    $this->stream = null;
    return $stream;
  }

  public function __destruct()
  {
    $this->close();
  }

  /**
   * 关闭数据流，释放底层资源（如文件句柄、网络连接等）。
   *
   * @return void
   */
  public function close(): void
  {
    if (is_resource($this->stream)) fclose($this->stream);
  }

  /**
   * 获取流资源
   *
   * @access public
   * @return resource
   */
  public function getStream()
  {
    return $this->stream;
  }

  /**
   * 获取流的大小（字节数），如果不可知则返回 null
   *
   * @access public
   * @return int|null Returns the size in bytes if known, or null of unknown.
   */
  public function getSize(): ?int
  {
    if (!is_resource($this->stream)) return null;
    // 获取流的大小（字节数）
    return fstat($this->stream)['size'];
  }

  /**
   * 返回当前流的读/写指针位置。
   *
   * @access public
   * @return int Position of the file pointer
   * @throws RuntimeException on error.
   */
  public function tell(): int
  {
    if (!is_resource($this->stream)) {
      throw new RuntimeException('FileStream is not a resource.');
    }
    // 返回当前流的读/写指针位置
    return ftell($this->stream);
  }

  /**
   * 检查是否已到达流的末尾。
   *
   * @access public
   * @return bool
   */
  public function eof(): bool
  {
    if (!is_resource($this->stream)) {
      return true;
    }
    // 检查是否已到达流的末尾
    return feof($this->stream);
  }

  /**
   * 将读/写指针重置到流的开头
   *
   * If the stream is not seekable, this method will raise an exception;
   * otherwise, it will perform a seek(0).
   *
   * @throws RuntimeException on failure.
   * @link http://www.php.net/manual/en/function.fseek.php
   * @see seek()
   */
  public function rewind(): void
  {
    $this->seek(0);
  }

  /**
   * 将读/写指针移动到流中的指定位置。
   *
   * @link http://www.php.net/manual/en/function.fseek.php
   * @param int $offset FileStream offset
   * @param int $whence Specifies how the cursor position will be calculated
   * @throws RuntimeException on failure.
   */
  public function seek(int $offset, int $whence = SEEK_SET): void
  {
    if (!$this->isSeekable()) {
      throw new RuntimeException('FileStream is not seekable.');
    }
    // 将读/写指针移动到流中的指定位置
    fseek($this->stream, $offset, $whence);
  }

  /**
   * 检查流是否支持随机访问（seek）
   *
   * @return bool
   */
  public function isSeekable(): bool
  {
    // 检查流是否支持随机访问（seek）
    return is_resource($this->stream) && stream_get_meta_data($this->stream)['seekable'];
  }

  /**
   * 向流中写入数据，并返回写入的字节数。
   *
   * @param string $string 要写入的字符串.
   * @return int 返回写入流的字节数。
   * @throws RuntimeException on failure.
   */
  public function write(string $string): int
  {
    if (!$this->isWritable()) {
      throw new RuntimeException('FileStream is not writable.');
    }
    // 向流中写入数据，并返回写入的字节数
    return fwrite($this->stream, $string);
  }

  /**
   * 检查流是否可写。
   *
   * @return bool
   */
  public function isWritable(): bool
  {
    if (!is_resource($this->stream)) return false;
    return in_array(
      stream_get_meta_data($this->stream)['mode'],
      ['r+b', 'wb', 'w+b', 'ab', 'a+b', 'xb', 'x+b']
    );
  }

  /**
   * 从流中读取指定长度的数据。
   *
   * @param int $length Read up-to-$length bytes from the object and return
   *     Then. Fewer than $length bytes may be returned if underlying stream
   *     call returns fewer bytes.
   * @return string Returns the data read from the stream, or an empty string
   *     if no bytes are available.
   * @throws RuntimeException if an error occurs.
   */
  public function read(int $length): string
  {
    if (!$this->isReadable()) {
      throw new RuntimeException('FileStream is not readable.');
    }
    // 从流中读取指定长度的数据
    return fread($this->stream, $length);
  }

  /**
   * 检查流是否可读。
   *
   * @return bool
   */
  public function isReadable(): bool
  {
    if (!is_resource($this->stream)) return false;
    return in_array(
      stream_get_meta_data($this->stream)['mode'],
      ['rb', 'r+b', 'w+b', 'a+b', 'x+b']
    );
  }

  /**
   * 读取整个流的内容并返回。
   *
   * @access public
   * @return string
   * @throws RuntimeException if unable to read or an error occurs while reading.
   */
  public function getContents(): string
  {
    if (!$this->isReadable()) {
      throw new RuntimeException('FileStream is not readable.');
    }
    // 读取整个流的内容并返回
    return stream_get_contents($this->stream, -1, 0);
  }

  /**
   * 获取流的元数据信息，可以传递一个键来获取特定的元数据。
   *
   * @access public
   * @param string|null $key Specific metadata to retrieve.
   * @return array|mixed|null 如果未提供键，则返回关联数组。
   * 如果提供了键并且找到了值，则返回特定的键值；
   * 如果找不到键，则返回null。
   */
  public function getMetadata(?string $key = null): mixed
  {
    // 获取流的元数据信息，可以传递一个键来获取特定的元数据
    $metadata = stream_get_meta_data($this->stream);
    if ($key === null) {
      return $metadata;
    } elseif (array_key_exists($key, $metadata)) {
      return $metadata[$key];
    } else {
      return null;
    }
  }
}
