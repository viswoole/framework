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

namespace Viswoole\Router\ApiDoc\Structure;

/**
 * 结构声明基类
 */
class BaseTypeStructure
{
  /**
   * @var string 结构名称
   */
  public string $name;
  /**
   * @var BaseTypeStructure[]|string[] 类型额外属性列表
   */
  public array $properties = [];
  /**
   * @var string 类型
   */
  public readonly string $type;

  /**
   * @param Types $type 基本类型
   * @param string|null $name 类型名称
   */
  public function __construct(Types $type = Types::Mixed, string $name = null)
  {
    $type = strtolower($type->name);
    $this->type = $type;
    $this->name = $name ?? $this->type;
  }

  /**
   * 获取类型
   *
   * @return string
   */
  public function getType(): string
  {
    return $this->type;
  }

  /**
   * 获取类型名称
   *
   * @return string
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * 转换为字符串
   *
   * @return string json字符串
   */
  public function __toString(): string
  {
    return $this->name;
  }
}
