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

use ArrayAccess;
use ArrayIterator;
use BadMethodCallException;
use Countable;
use IteratorAggregate;
use Override;
use Viswoole\HttpServer\Contract\RequestInterface;
use Viswoole\HttpServer\Message\FileStream;
use Viswoole\HttpServer\Message\UploadedFile;

/**
 * 用于自动注入上传的文件，如果需注入指定name的文件，
 * 则需配合\Viswoole\HttpServer\Validate\FileRule使用。
 *
 * 实现了__call魔术方法，可直接调用UploadFile对象的方法，只适用于上传的文件只有一个时。
 * @method FileStream getStream() 获取流
 * @method void moveTo(string $targetPath) 移动文件到指定目录
 * @method bool isMoved() 判断文件是否已移动
 * @method int getError() 获取状态码，0为正常
 * @method int getSize() 获取文件大小
 * @method string getClientFilename() 获取文件名称
 * @method string getClientMediaType() 获取媒体类型
 */
class File implements ArrayAccess, IteratorAggregate, Countable
{
  /**
   * @var string post上传时的参数名
   */
  public string $name = '';
  /**
   * @var UploadedFile[]|array<string,UploadedFile[]> 文件对象列表
   */
  public array $list;

  /**
   * @param RequestInterface $request
   */
  public function __construct(RequestInterface $request)
  {
    $this->list = $request->files() ?? [];
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return isset($this->list[$offset]);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): ?UploadedFile
  {
    return $this->list[$offset] ?? null;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    throw new BadMethodCallException('File is read-only');
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    throw new BadMethodCallException('File is read-only');
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getIterator(): ArrayIterator
  {
    return new ArrayIterator($this->list);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function count(): int
  {
    return count($this->list);
  }

  /**
   * @param string $name
   * @param array $arguments
   * @return mixed
   */
  public function __call(string $name, array $arguments)
  {
    if ($this->isEmpty()) throw new BadMethodCallException('File is empty');

    if (empty($name)) {
      // list存储的是全部上传的文件，则默认操作第一个
      return $this->list[array_key_first($this->list)]->{$name}(...$arguments);
    } elseif (method_exists($this->list[0], $name)) {
      return $this->list[0]->{$name}(...$arguments);
    } else {
      throw new BadMethodCallException('Method not exists: ' . static::class . '::' . $name);
    }
  }

  /**
   * 判断是否为空
   *
   * @return bool
   */
  public function isEmpty(): bool
  {
    return empty($this->list);
  }
}
