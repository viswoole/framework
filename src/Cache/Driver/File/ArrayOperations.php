<?php

declare(strict_types=1);

namespace Viswoole\Cache\Driver\File;

use Override;

/** 文件缓存的数组操作组合，仅通过驱动的 get/set 访问缓存数据。 */
trait ArrayOperations
{
  /**
   * @inheritDoc
   */
  #[Override]
  public function sAddArray(
    string       $key,
    array|string $values,
  ): false|int
  {
    if (is_string($values)) $values = [$values];

    $oldArray = $this->getArray($key);

    $oldArray = $oldArray === false ? [] : $oldArray;

    $newArray = array_merge($oldArray, $values);

    $oldLen = count($oldArray);

    $newLen = count($newArray);

    if ($oldLen === $newLen) return false;
    $result = $this->set($key, $newArray);
    return $result ? $newLen - $oldLen : false;
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function getArray(string $key): array|false
  {
    $result = $this->get($key);
    if ($result === null) return false;
    return is_array($result) ? $result : [$result];
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function sRemoveArray(
    string       $key,
    array|string $values,
  ): false|int
  {
    $array = $this->getArray($key);
    if (empty($array)) return 0;
    if (is_string($values)) $values = [$values];
    $newArray = array_filter($array, function (mixed $value) use ($values): bool {
      return !in_array($value, $values);
    });
    $count = count($array) - count($newArray);
    $result = $this->set($key, $newArray);
    return $result ? $count : false;
  }
}
