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

namespace Viswoole\HttpServer\Rules;

use Attribute;
use Override;
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate\Rules\RuleAbstract;
use Viswoole\HttpServer\Facade\Request;

/**
 * Http 请求头验证
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Header extends RuleAbstract
{
  /**
   * @param string $name 标头
   * @param bool $require 是否必须
   * @param string $message
   */
  public function __construct(
    public readonly string $name,
    public readonly bool   $require = true,
    string                 $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 验证数据
   *
   * @param mixed $value
   * @return mixed 返回校验后的数据
   * @throws ValidateException 验证失败请抛出异常或调用$this->error()
   */
  #[Override] public function validate(mixed $value): mixed
  {
    $headerValue = Request::getHeader($this->name);
    if (empty($headerValue) && $this->require) {
      $this->error("Http header $this->name is required");
    } elseif ($value instanceof \Viswoole\HttpServer\AutoInject\Header) {
      $value->name = $this->name;
      $value->value = $headerValue;
    } else {
      $value = $headerValue;
    }
    return $value;
  }
}
