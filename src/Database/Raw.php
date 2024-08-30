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

namespace Viswoole\Database;

/**
 * 原生SQL
 */
class Raw
{
  /**
   * @param string $sql
   * @param array $bindings
   */
  public function __construct(public string $sql, public array $bindings = [])
  {
  }

  /**
   * 转换为字符串
   *
   * @return string
   */
  public function __toString(): string
  {
    return self::merge($this->sql, $this->bindings);
  }

  /**
   * 合并参数
   *
   * @param string $sql
   * @param array $bindings
   * @return string
   */
  public static function merge(string $sql, array $bindings): string
  {
    if (!empty($bindings)) {
      // 分离位置占位符和命名占位符
      $positionBindings = [];
      $namedBindings = [];
      foreach ($bindings as $key => $value) {
        if (is_array($value)) {
          $value = implode(',', $value);
        } elseif (is_string($value)) {
          $value = "'$value'";
        } elseif (is_null($value)) {
          $value = 'NULL';
        } else {
          $value = (string)$value;
        }
        if (is_int($key)) {
          $positionBindings[] = $value;
        } else {
          $namedBindings[$key] = $value;
        }
      }
      // 替换位置占位符 '?'
      $sql = preg_replace_callback('/\?/', function () use (&$positionBindings) {
        return array_shift($positionBindings);
      }, $sql);
      // 替换命名占位符 ':name'
      foreach ($namedBindings as $key => $value) {
        $placeholder = ":$key";
        $sql = str_replace($placeholder, $value, $sql);
      }
    }
    return $sql;
  }

  /**
   * 将绑定的参数合并到sql语句中
   *
   * @access public
   * @return string
   */
  public function toSql(): string
  {
    return self::merge($this->sql, $this->bindings);
  }
}
