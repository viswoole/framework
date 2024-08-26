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
 * 数组辅助类
 */
class Arr
{
  /**
   * 判断是否为关联数组
   *
   * @access public
   * @param array $array
   * @return bool
   */
  public static function isAssociativeArray(array $array): bool
  {
    return !self::isIndexArray($array);
  }

  /**
   * 判断是否为索引数组
   *
   * @access public
   * @param array $array
   * @return bool
   */
  public static function isIndexArray(array $array): bool
  {
    if (empty($array)) return true;
    // 检查数组的第一个键是否为 0 并且所有的键都是连续的整数
    $keys = array_keys($array);
    return $keys[0] === 0 && $keys === range(0, count($array) - 1);
  }

  /**
   * 从数组中弹出指定下标的值
   *
   * @access public
   * @param array $array 数组
   * @param string|int $key 下标键
   * @param mixed|null $default 默认值
   * @return mixed
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
