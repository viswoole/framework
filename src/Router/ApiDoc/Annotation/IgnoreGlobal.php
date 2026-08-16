<?php
/*
 *  +----------------------------------------------------------------------
 *  | Viswoole [基于swoole开发的高性能快速开发框架]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2024 https://viswoole.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
 *  +----------------------------------------------------------------------
 *  | Author: ZhuChonglin <8210856@qq.com>
 *  +----------------------------------------------------------------------
 */

declare (strict_types=1);

namespace Viswoole\Router\ApiDoc\Annotation;

use Attribute;
use InvalidArgumentException;

/**
 * 全局参数排除注解
 *
 * 标注到控制器类或路由处理方法上，用于排除 config/router.php 中
 * api_doc 全局配置（header/query/body/returned）在当前接口的生效，
 * 解决"多数接口需要全局参数（如鉴权头 authorization），少数接口不需要"的场景。
 *
 * 用法示例：
 * ```
 * // 排除全部全局配置（header+query+body+returned）
 * #[IgnoreGlobal]
 * // 仅排除全局请求头
 * #[IgnoreGlobal('header')]
 * // 排除全局请求头与查询参数
 * #[IgnoreGlobal(['header', 'query'])]
 * // 任意来源中名为 authorization 的全局字段（如全局鉴权头）
 * #[IgnoreGlobal(name: 'authorization')]
 * // 精确排除全局 header 中的 authorization 字段
 * #[IgnoreGlobal('header', 'authorization')]
 * ```
 * 注解可重复标注，多条规则叠加生效；标注到控制器类上时对类内所有路由方法生效。
 * 注意：排除仅作用于全局配置，方法上通过 #[InjectHeader] 等注解声明的局部参数不受影响，
 * 因此被排除的接口仍可按需声明个别参数。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class IgnoreGlobal
{
  /** 来源：请求头 */
  public const string SOURCE_HEADER = 'header';
  /** 来源：查询参数 */
  public const string SOURCE_QUERY = 'query';
  /** 来源：请求体参数 */
  public const string SOURCE_BODY = 'body';
  /** 来源：返回值声明 */
  public const string SOURCE_RETURNED = 'returned';
  /**
   * 合法来源列表
   */
  public const array SOURCES = [
    self::SOURCE_HEADER,
    self::SOURCE_QUERY,
    self::SOURCE_BODY,
    self::SOURCE_RETURNED,
  ];
  /**
   * @var array<string>|null 排除的参数来源列表，null 表示全部来源
   */
  public readonly ?array $source;
  /**
   * @var array<string>|null 排除的字段名列表，null 表示来源内全部字段
   */
  public readonly ?array $name;

  /**
   * @param string|array|null $source 排除的参数来源（header/query/body/returned），null 表示全部来源
   * @param string|array|null $name 排除的字段名，null 表示全部字段；returned 来源时匹配返回声明标题
   */
  public function __construct(
    string|array|null $source = null,
    string|array|null $name = null,
  )
  {
    // 归一化为数组，便于 matches 统一判断
    if (is_string($source)) $source = [$source];
    if (is_string($name)) $name = [$name];
    // 提前校验来源合法性，避免配置错误延迟到文档生成时才暴露
    if ($source !== null) {
      foreach ($source as $item) {
        if (!in_array($item, self::SOURCES, true)) {
          throw new InvalidArgumentException(
            'IgnoreGlobal注解source参数无效:' . $item
            . '，有效值:' . implode('/', self::SOURCES)
          );
        }
      }
    }
    $this->source = $source;
    $this->name = $name;
  }

  /**
   * 判断指定来源与字段名是否命中本排除规则
   *
   * @param string $source 参数来源
   * @param string|null $name 字段名（returned 来源时为标题），null 时仅按来源匹配
   * @return bool 命中返回 true
   */
  public function matches(string $source, ?string $name = null): bool
  {
    // 来源不匹配则规则未命中
    if ($this->source !== null && !in_array($source, $this->source, true)) return false;
    // 未限定字段名，视为排除整个来源
    if ($this->name === null || $name === null) return true;
    return in_array($name, $this->name, true);
  }
}
