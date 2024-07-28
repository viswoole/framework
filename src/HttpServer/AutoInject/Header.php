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

namespace Viswoole\HttpServer\AutoInject;

use Viswoole\HttpServer\Facade\Request;

class Header
{
  public bool $allowNewInstance = true;
  /**
   * @var string 标头
   */
  public string $name;
  /**
   * @var string 标头值
   */
  public string $value;

  public function __toString(): string
  {
    return $this->value ?? var_export($this->all(), true);
  }

  /**
   * 获取当前请求所有标头
   *
   * @return array
   */
  public function all(): array
  {
    return Request::getHeader(default: []);
  }
}
