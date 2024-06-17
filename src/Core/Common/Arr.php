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
   * 判断是否为索引数组
   *
   * @access public
   * @param array $array
   * @return bool
   */
  public static function isIndexArray(mixed $array): bool
  {
    if (!is_array($array)) return false;
    return is_numeric(implode('', array_keys($array)));
  }

  /**
   * 判断是否为关联数组
   *
   * @access public
   * @param array $array
   * @param bool $strict 是否严格检测为纯关联数组，不包含int键,默认严格检测
   * @return bool
   */
  public static function isAssociativeArray(mixed $array, bool $strict = true): bool
  {
    if (!is_array($array)) return false;
    $keys = array_keys($array);
    if (is_numeric(implode('', $keys))) return false;
    if (!$strict) return true;
    foreach ($keys as $key) if (is_int($key)) return false;
    return true;
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
