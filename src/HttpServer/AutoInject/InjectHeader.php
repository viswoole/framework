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

use Attribute;
use Override;
use Viswoole\Core\Validate\Rules\RuleAbstract;
use Viswoole\HttpServer\Facade\Request;
use Viswoole\HttpServer\Request\Header;

/**
 * 自动注入请求标头
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class InjectHeader extends RuleAbstract
{
  /**
   * @param string $key 标头，为空则为参数名
   * @param string $message
   */
  public function __construct(
    private string $key = '',
    string         $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 验证数据
   *
   * @param mixed $value
   * @param string $key 参数名
   * @return mixed 返回校验后的数据
   */
  #[Override] public function validate(mixed $value, string $key = ''): mixed
  {
    $this->key = empty($this->key) ? $key : $this->key;
    $headerValue = Request::getHeader($this->key);
    if (empty($headerValue)) {
      if (is_null($value)) return null;
      $this->error("必须具有 $this->key 请求标头");
    }
    if (!$value instanceof Header) $value = new Header();
    $value->inject($this->key, $headerValue);
    return $value;
  }
}
