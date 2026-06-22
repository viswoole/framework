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
 * HTTP 标头验证与格式化工具
 *
 * 提供标头名称/值的合法性校验、名称大小写格式化、
 * 标头值数组/字符串互转、不区分大小写的标头存在性判断等能力。
 */
class Header
{
  /**
   * 校验标头名称和值的合法性
   *
   * 名称不得为空或包含换行符、回车符、冒号（防止 CRLF 注入）；
   * 值不得为空字符串或空数组。
   *
   * @param string $name 标头名称
   * @param string|string[] $value 标头值，支持单个字符串或字符串数组
   * @throws InvalidArgumentException 名称或值不合法时抛出
   */
  public static function validate(string $name, array|string $value): void
  {
    // 修复: 增加 \r 检查，防止 CRLF 注入攻击
    if (empty($name) || str_contains($name, "\n") || str_contains($name, "\r") || str_contains($name, ':')) {
      throw new InvalidArgumentException("无效的头部字段名称:$name");
    }
    // 验证头部字段值
    // 修复: 使用精确的空值检查替代 empty()，避免运算符优先级导致条件语义不明确
    if ($value === '' || $value === []) {
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
   * 批量格式化标头的值类型和名称大小写
   *
   * @param array $headers 原始标头键值对
   * @param string $valueMode 值输出模式：'array' 将逗号分隔的字符串拆分为数组，其它将数组用逗号拼接为字符串
   * @param false|string $nameModel 名称格式化模式：false 保留原样，'lower' 全小写，'upper' 全大写，'title' 首字母大写
   * @return array 格式化后的标头数组
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
        // 修复: 过滤 explode 产生的空字符串值，避免引入无效空元素
        if (is_string($value)) $value = array_filter(explode(',', $value), fn($v) => $v !== '');
      } elseif (is_array($value)) {
        $value = implode(',', $value);
      }
      $newHeaders[$key] = $value;
    }
    return $newHeaders;
  }

  /**
   * 格式化标头名称的大小写
   *
   * @param string $name 原始标头名称
   * @param string $formatModel 格式化模式：'title' 首字母大写（默认），'upper' 全大写，'lower' 全小写
   * @return string 格式化后的标头名称
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
   * 不区分大小写地检查标头是否存在，存在时返回真实的标头键名
   *
   * @param string $name 待查找的标头名称（不区分大小写）
   * @param array $headers 标头键值对
   * @return false|string 存在时返回真实的标头键名，不存在返回 false
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
}
