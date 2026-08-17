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

/**
 * 文件流封装
 *
 * 对 PHP 文件资源流的面向对象封装，提供读取、写入、定位、
 * 元数据查询等操作，析构时自动关闭底层资源。
 */
class FileStream
{
  /**
   * @var resource 底层 PHP 文件资源流
   */
  protected $stream;

  public function __construct(public readonly string $filePath, string $mode = 'r')
  {
    $stream = fopen($filePath, $mode);
    if (!$stream) throw new RuntimeException('无法打开文件流：' . $filePath);
    $this->stream = $stream;
  }

  /**
   * 工厂方法：创建流实例
   *
   * @param string $filePath 文件路径
   * @param string $mode 打开模式，参考 https://www.php.net/manual/zh/function.fopen.php
   * @return static 新创建的流实例
   */
  public static function create(string $filePath, string $mode = 'r'): static
  {
    return new static($filePath, $mode);
  }

  /**
   * 读取整个流内容并作为字符串返回
   *
   * @return string 流内容，资源已分离时返回空字符串
   */
  public function __toString(): string
  {
    if (!is_resource($this->stream)) return '';
    // 回退指针
    rewind($this->stream);
    return stream_get_contents($this->stream);
  }

  /**
   * 分离并返回底层 PHP 资源流，此后本对象不再可用
   *
   * @return resource|null 底层资源流，已分离时返回 null
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
   * 关闭底层资源流并释放文件句柄
   */
  public function close(): void
  {
    if (is_resource($this->stream)) fclose($this->stream);
  }

  /**
   * 获取底层 PHP 资源流
   *
   * @return resource|null 资源流
   */
  public function getStream()
  {
    return $this->stream;
  }

  /**
   * 获取流的大小（字节数）
   *
   * @return int|null 字节数，资源已分离或不可知时返回 null
   */
  public function getSize(): ?int
  {
    if (!is_resource($this->stream)) return null;
    return fstat($this->stream)['size'];
  }

  /**
   * 获取当前读写指针位置
   *
   * @return int 指针偏移量
   * @throws RuntimeException 资源已分离时抛出
   */
  public function tell(): int
  {
    if (!is_resource($this->stream)) {
      throw new RuntimeException('FileStream is not a resource.');
    }
    return ftell($this->stream);
  }

  /**
   * 判断是否到达流末尾
   *
   * @return bool 资源已分离时返回 true，否则返回 feof 结果
   */
  public function eof(): bool
  {
    if (!is_resource($this->stream)) {
      return true;
    }
    return feof($this->stream);
  }

  /**
   * 将指针重置到流开头（等价于 seek(0)）
   *
   * @throws RuntimeException 流不可定位时抛出
   */
  public function rewind(): void
  {
    $this->seek(0);
  }

  /**
   * 将读写指针移动到指定位置
   *
   * @param int $offset 偏移量
   * @param int $whence 定位方式（SEEK_SET、SEEK_CUR、SEEK_END）
   * @throws RuntimeException 流不可定位时抛出
   * @link http://www.php.net/manual/en/function.fseek.php
   */
  public function seek(int $offset, int $whence = SEEK_SET): void
  {
    if (!$this->isSeekable()) {
      throw new RuntimeException('FileStream is not seekable.');
    }
    fseek($this->stream, $offset, $whence);
  }

  /**
   * 判断流是否支持随机定位（seek）
   *
   * @return bool 可定位返回 true
   */
  public function isSeekable(): bool
  {
    return is_resource($this->stream) && stream_get_meta_data($this->stream)['seekable'];
  }

  /**
   * 向流中写入数据
   *
   * @param string $string 待写入的字符串
   * @return int 实际写入的字节数
   * @throws RuntimeException 流不可写时抛出
   */
  public function write(string $string): int
  {
    if (!$this->isWritable()) {
      throw new RuntimeException('FileStream is not writable.');
    }
    return fwrite($this->stream, $string);
  }

  /**
   * 判断流是否可写
   *
   * @return bool 可写返回 true
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
   * 从流中读取指定长度的数据
   *
   * @param int $length 最大读取字节数
   * @return string 读取到的数据，无可读数据时返回空字符串
   * @throws RuntimeException 流不可读时抛出
   */
  public function read(int $length): string
  {
    if (!$this->isReadable()) {
      throw new RuntimeException('FileStream is not readable.');
    }
    return fread($this->stream, $length);
  }

  /**
   * 判断流是否可读
   *
   * @return bool 可读返回 true
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
   * 从当前位置读取全部剩余内容
   *
   * @return string 流内容
   * @throws RuntimeException 流不可读时抛出
   */
  public function getContents(): string
  {
    if (!$this->isReadable()) {
      throw new RuntimeException('FileStream is not readable.');
    }
    return stream_get_contents($this->stream, -1, 0);
  }

  /**
   * 获取流的元数据信息
   *
   * @param string|null $key 指定键名则返回对应值，不传则返回完整元数据数组
   * @return array|mixed|null 键存在时返回对应值，不存在返回 null；不传 key 时返回完整数组
   */
  public function getMetadata(?string $key = null): mixed
  {
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
