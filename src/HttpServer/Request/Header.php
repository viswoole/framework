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

namespace Viswoole\HttpServer\Request;

/**
 * 注入请求标头
 *
 * 必须配合\Viswoole\HttpServer\AutoInject\InjectHeader注解使用。
 *
 * 实现了__toString()方法，可以直接转换为字符串。
 */
class Header
{
  /**
   * @var string 标头
   */
  private string $key;
  /**
   * @var string 标头值
   */
  private string $value;

  /**
   * 转换为字符串
   *
   * @return string
   */
  public function __toString(): string
  {
    return $this->value;
  }

  /**
   * 获取标头
   *
   * @access public
   * @return string
   */
  public function getKey(): string
  {
    return $this->key;
  }

  /**
   * 获取标头值
   *
   * @return string
   */
  public function value(): string
  {
    return $this->value;
  }

  /**
   * 注入标头和值
   *
   * @param string $key
   * @param string $value
   * @return void
   */
  public function inject(string $key, string $value): void
  {
    $this->key = $key;
    $this->value = $value;
  }
}
