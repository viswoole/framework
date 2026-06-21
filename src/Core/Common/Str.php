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
   * @param string $input 驼峰命名字符串
   * @return string 蛇形命名字符串
   */
  public static function camelCaseToSnakeCase(string $input): string
  {
    $output = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
    return strtolower($output);
  }

  /**
   * 蛇形到骆驼形(单词首字母大写)
   *
   * @param string $input 蛇形命名字符串
   * @return string 驼峰命名字符串
   */
  public static function snakeCaseToCamelCase(string $input): string
  {
    $words = explode('_', $input);
    /** @noinspection SpellCheckingInspection */
    return lcfirst(implode('', array_map('ucfirst', $words)));
  }
}
