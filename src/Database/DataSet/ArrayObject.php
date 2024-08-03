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

namespace Viswoole\Database\DataSet;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Override;

class ArrayObject implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
  protected array $data = [];

  /**
   * @inheritDoc
   */
  #[Override] public function offsetGet(mixed $offset): mixed
  {
    // 如果出现偏移访问，则抛出警告
    if (!$this->offsetExists($offset)) {
      trigger_error("Undefined array key $offset", E_USER_WARNING);
      return null;
    } else {
      return $this->data[$offset];
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetExists(mixed $offset): bool
  {
    return array_key_exists($offset, $this->data);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetSet(mixed $offset, mixed $value): void
  {
    $this->data[$offset] = $value;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function offsetUnset(mixed $offset): void
  {
    unset($this->data[$offset]);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function count(): int
  {
    return count($this->data);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getIterator(): ArrayIterator
  {
    return new ArrayIterator($this->data);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function jsonSerialize(): array
  {
    return $this->data;
  }
}
