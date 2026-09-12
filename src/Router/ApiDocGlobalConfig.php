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

namespace Viswoole\Router;

use InvalidArgumentException;
use Viswoole\Core\Config;
use Viswoole\Router\ApiDoc\Annotation\Returned;
use Viswoole\Router\ApiDoc\Structure\FieldStructure;
use Viswoole\Router\ApiDoc\Structure\Types;

/**
 * API 文档全局参数配置校验器
 *
 * 负责校验并归一化 router.api_doc.body/header/query/returned 配置项：
 * 将三种配置格式（FieldStructure 实例、极简字符串、关联数组）统一转换为
 * FieldStructure 实例并写回配置，供路由解析与文档生成时直接使用。
 * 仅在 router.api_doc.enable 时由 Router 构造器调用
 */
final class ApiDocGlobalConfig
{
  /**
   * 校验并归一化全部全局参数配置
   *
   * @param Config $config 框架配置实例（归一化结果写回配置）
   */
  public static function verify(Config $config): void
  {
    self::verifyGlobalParams($config, 'router.api_doc.body');
    self::verifyGlobalParams($config, 'router.api_doc.header');
    self::verifyGlobalParams($config, 'router.api_doc.query');
    self::verifyGlobalReturned($config);
  }

  /**
   * 验证全局参数，归一化后写回配置
   *
   * @param Config $config 配置实例
   * @param string $name 配置键名（router.api_doc.body/header/query 之一）
   */
  private static function verifyGlobalParams(Config $config, string $name): void
  {
    $params = $config->get($name, []);
    if (empty($params)) return;
    if (!is_array($params)) {
      throw new InvalidArgumentException("$name 配置错误，必须是数组类型");
    }
    $newParams = [];
    foreach ($params as $index => $field) {
      $fieldStructure = self::toFieldStructure($field, $name, $index);
      $newParams[$fieldStructure->name] = $fieldStructure;
    }
    $config->set($name, $newParams);
  }

  /**
   * 将全局参数配置项转换为字段结构实例
   *
   * 支持三种配置格式：
   * 1. FieldStructure 实例
   * 2. 极简格式：'参数名' => '参数描述'（类型默认 string）
   * 3. 关联数组：['name'=>..., 'description'=>..., 'allowNull'=>..., 'default'=>..., 'type'=>...]
   *    type 支持 Types 枚举或类型字符串（string/int/float/bool/array/object）
   *
   * @param mixed $field 配置项
   * @param string $configName 配置名称（用于异常提示）
   * @param int|string $index 配置键（极简格式下作为参数名）
   * @return FieldStructure
   */
  private static function toFieldStructure(mixed $field, string $configName, int|string $index
  ): FieldStructure
  {
    if ($field instanceof FieldStructure) return $field;
    if (is_string($field) && is_string($index)) {
      // 极简格式：参数名 => 描述
      return new FieldStructure($index, $field, type: Types::String);
    }
    if (is_array($field)) {
      $fieldName = $field['name'] ?? (is_string($index) ? $index : null);
      if (empty($fieldName)) {
        throw new InvalidArgumentException("$configName($index) 配置错误，缺少name字段");
      }
      return new FieldStructure(
        (string)$fieldName,
        (string)($field['description'] ?? ''),
        (bool)($field['allowNull'] ?? false),
        $field['default'] ?? null,
        self::parseType($field['type'] ?? Types::Mixed)
      );
    }
    throw new InvalidArgumentException(
      "$configName($index) 配置错误，必须是FieldStructure实例、字符串或数组"
    );
  }

  /**
   * 解析类型配置为内置类型枚举
   *
   * @param mixed $type Types枚举或类型字符串（string/int/float/bool/array/object）
   * @return Types
   */
  private static function parseType(mixed $type): Types
  {
    if ($type instanceof Types) return $type;
    if (is_string($type)) {
      return match (strtolower($type)) {
        'string', 'str' => Types::String,
        'int', 'integer' => Types::Int,
        'float', 'double' => Types::Float,
        'bool', 'boolean' => Types::Bool,
        'array' => Types::Array,
        'object' => Types::Object,
        'null' => Types::Null,
        default => Types::Mixed,
      };
    }
    return Types::Mixed;
  }

  /**
   * 校验全局返回值配置
   *
   * @param Config $config 配置实例
   */
  private static function verifyGlobalReturned(Config $config): void
  {
    $globalReturned = $config->get('router.api_doc.returned', []);
    if (!is_array($globalReturned)) {
      throw new InvalidArgumentException('router.api_doc.returned 配置错误，必须是数组类型');
    }
    $class = Returned::class;
    foreach ($globalReturned as $item) {
      if (!$item instanceof Returned) {
        throw new InvalidArgumentException("router.api_doc.returned 配置错误，必须是{$class}实例");
      }
    }
  }
}
