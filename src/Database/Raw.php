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

use JsonSerializable;
use Override;

/**
 * 原生SQL表达式
 *
 * 用于在查询构建器中嵌入不被参数绑定的SQL片段，
 * 如函数调用、子查询等。支持位置占位符(?)和命名占位符(:name)。
 */
class Raw implements JsonSerializable
{
  /**
   * @param string $sql SQL语句，支持占位符
   * @param array $bindings 绑定参数
   */
  public function __construct(public string $sql, public array $bindings = [])
  {
  }

  /**
   * 将绑定参数合并到SQL语句中，返回完整的SQL字符串
   *
   * @return string 合并参数后的SQL语句
   */
  public function __toString(): string
  {
    return self::merge($this->sql, $this->bindings);
  }

  /**
   * 将绑定参数合并到SQL语句中的静态方法
   *
   * 自动识别位置占位符(?)和命名占位符(:name)，对字符串值进行转义。
   *
   * @param string $sql SQL语句
   * @param array $bindings 绑定参数
   * @return string 合并参数后的SQL语句
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
        // 修复#4: 对字符串值中的单引号进行转义，防止SQL语法错误
        } elseif (is_string($value)) {
          $value = "'" . str_replace("'", "\\'", $value) . "'";
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
   * @inheritDoc
   */
  #[Override] public function jsonSerialize(): string
  {
    return $this->toString();
  }

  /**
   * 将绑定参数合并到SQL语句中，返回可读的SQL字符串
   *
   * @return string 合并参数后的SQL语句
   */
  public function toString(): string
  {
    return self::merge($this->sql, $this->bindings);
  }
}
