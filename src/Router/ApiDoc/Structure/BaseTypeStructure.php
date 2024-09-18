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

use InvalidArgumentException;

/**
 * 结构声明基类
 */
class BaseTypeStructure
{
  const array BASE_TYPE_LIST = ['object', 'array', 'string', 'int', 'float', 'bool', 'null', 'File', 'mixed', 'enum'];
  /**
   * @var string 结构名称
   */
  public string $name;

  /**
   * @param string $type 基本类型 [object|array|string|int|float|bool|null|File|mixed]
   * @see self::BASE_TYPE_LIST
   */
  public function __construct(public readonly string $type)
  {
    if (!in_array($this->type, self::BASE_TYPE_LIST)) {
      throw new InvalidArgumentException(
        "基本类型错误{$this->type}，可选值：" . implode('|', self::BASE_TYPE_LIST)
      );
    }
    $this->name = $this->type;
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
