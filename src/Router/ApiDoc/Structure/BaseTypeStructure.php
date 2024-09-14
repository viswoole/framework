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
use JsonSerializable;

/**
 * 结构声明基类
 */
class BaseTypeStructure implements JsonSerializable
{
  const array BASE_TYPE_LIST = ['object', 'array', 'string', 'int', 'float', 'bool', 'null', 'File', 'mixed', 'enum'];
  /**
   * @var string 结构名称
   */
  protected string $name;

  /**
   * @param string $type 基本类型[object|array|string|int|float|bool|null|File|mixed]
   */
  public function __construct(protected readonly string $type)
  {
    if (!in_array($this->type, self::BASE_TYPE_LIST)) {
      throw new InvalidArgumentException(
        "基本类型错误{$this->type}，可选值：" . implode('|', self::BASE_TYPE_LIST)
      );
    }
  }

  /**
   * 转换为数组
   *
   * @param bool $recursion 递归
   * @return array
   */
  public function toArray(bool $recursion = true): array
  {
    if (!$recursion) {
      return $this->jsonSerialize();
    } else {
      return json_decode(
        json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $recursion
      );
    }
  }

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return [
      'name' => $this->getName(),
      'type' => $this->type,
    ];
  }

  /**
   * 获取结构名称
   *
   * @return string
   */
  public function getName(): string
  {
    if (!isset($this->name)) $this->name = $this->type;
    return $this->name;
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
   * 转换为字符串
   *
   * @return string json字符串
   */
  public function __toString(): string
  {
    return $this->getName();
  }
}
