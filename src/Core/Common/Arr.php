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

namespace Viswoole\Core\Common;
/**
 * 数组工具类，提供数组类型判断与安全取值等静态方法
 */
class Arr
{
  /**
   * 判断数组是否为关联数组（键名非连续整数从0开始）
   *
   * @param array $array 待判断的数组
   * @param bool $allowEmpty 空数组是否视为关联数组，默认 false 时空数组返回 false
   * @return bool 是关联数组返回 true，否则返回 false
   */
  public static function isAssociativeArray(array $array, bool $allowEmpty = false): bool
  {
    return !self::isIndexArray($array, $allowEmpty);
  }

  /**
   * 判断数组是否为索引数组（键名为从0开始的连续整数）
   *
   * @param array $array 待判断的数组
   * @param bool $allowEmpty 空数组是否视为索引数组，默认 false 时空数组返回 false
   * @return bool 是索引数组返回 true，否则返回 false
   */
  public static function isIndexArray(array $array, bool $allowEmpty = false): bool
  {
    if (empty($array)) return $allowEmpty;
    // 检查数组的第一个键是否为 0 并且所有的键都是连续的整数
    $keys = array_keys($array);
    return $keys[0] === 0 && $keys === range(0, count($array) - 1);
  }

  /**
   * 从数组中弹出指定键的值并从原数组中移除该键
   *
   * @param array $array 待操作的数组（引用传递，弹出后原数组会移除该键）
   * @param string|int $key 要弹出的键名
   * @param mixed|null $default 键不存在时返回的默认值
   * @return mixed 键存在时返回对应值，否则返回默认值
   */
  public static function arrayPopValue(array &$array, string|int $key, mixed $default = null): mixed
  {
    if (array_key_exists($key, $array)) {
      $value = $array[$key];
      unset($array[$key]);
      return $value;
    } else {
      return $default;
    }
  }
}
