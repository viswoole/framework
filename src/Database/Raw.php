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
    if (empty($bindings)) {
      return $sql;
    } else {
      return str_replace('?', array_shift($bindings), $sql);
    }
  }
}
