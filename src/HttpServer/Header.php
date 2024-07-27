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

namespace Viswoole\HttpServer;

use InvalidArgumentException;

/**
 * 请求头处理
 */
class Header
{
  /**
   * @var string 标头
   */
  public string $name;
  /**
   * @var string 标头值
   */
  public string $value;

  /**
   * 验证标头是否合法
   *
   * @param string $name 不区分大小写的头字段名称
   * @param string|string[] $value 标头值
   * @return void
   * @throws InvalidArgumentException 用于无效的标头名称或值
   */
  public static function validate(string $name, array|string $value): void
  {
    if (empty($name) || str_contains($name, "\n") || str_contains($name, ':')) {
      throw new InvalidArgumentException("无效的头部字段名称:$name");
    }
    // 验证头部字段值
    if (!is_string($value) && !is_array($value) || empty($value)) {
      throw new InvalidArgumentException("无效的头部字段值:$value");
    }
    if (is_array($value)) {
      foreach ($value as $k => $v) {
        if (!is_string($v) || empty($v)) {
          throw new InvalidArgumentException("无效的头部字段值:[$k=>$v]");
        }
      }
    }
  }

  /**
   * 格式化全部标头
   *
   * @access public
   * @param array $headers 标头数组
   * @param string $valueMode 标头值类型array|string
   * @param false|string $nameModel false则表示保留原标头名称，其他可选值为lower|upper|title
   * @return array 格式化完毕的标头
   */
  public static function formatHeaders(
    array        $headers,
    string       $valueMode = 'array',
    false|string $nameModel = false
  ): array
  {
    $newHeaders = [];
    foreach ($headers as $key => $value) {
      if (is_string($nameModel)) {
        $key = static::formatName($key, $nameModel);
      }
      if ($valueMode === 'array') {
        if (is_string($value)) $value = explode(',', $value) ?: [];
      } elseif (is_array($value)) {
        $value = implode(',', $value);
      }
      $newHeaders[$key] = $value;
    }
    return $newHeaders;
  }

  /**
   * 把标头名称格式化为首字母大写
   *
   * @param string $name 标头名称
   * @param string $formatModel 默认值title其他可选值为lower|upper
   * @return string
   */
  public static function formatName(string $name, string $formatModel = 'title'): string
  {
    if ($formatModel === 'title') {
      $name = strtolower($name);
      return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    } elseif ($formatModel === 'upper') {
      return strtoupper($name);
    } else {
      return strtolower($name);
    }
  }

  /**
   * 通过不区分大小标头判断是否存在
   *
   * @param string $name 不区分大小写标头名称
   * @param array $headers 标头数组
   * @return false|string 如存在返回真实标头，不存在则返回false
   */
  public static function hasHeader(string $name, array $headers): false|string
  {
    $lowercaseKey = strtolower($name);
    $lowercaseHeaders = array_change_key_case($headers);
    $realKey = array_search($lowercaseKey, array_keys($lowercaseHeaders));
    if ($realKey !== false) {
      $keys = array_keys($headers);
      return $keys[$realKey];
    } else {
      return false;
    }
  }

  public function __toString(): string
  {
    return $this->value;
  }
}
