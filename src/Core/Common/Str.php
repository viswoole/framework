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
 * 字符串辅助类
 */
class Str
{
  /**
   * 骆驼形到蛇形
   *
   * @param $input
   * @return string
   */
  public static function camelCaseToSnakeCase($input): string
  {
    $output = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
    return strtolower($output);
  }

  /**
   * 蛇形到骆驼形
   *
   * @param $input
   * @return string
   */
  public static function snakeCaseToCamelCase($input): string
  {
    $words = explode('_', $input);
    /** @noinspection SpellCheckingInspection */
    return lcfirst(implode('', array_map('ucfirst', $words)));
  }
}
