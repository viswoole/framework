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

namespace Viswoole\Core\Validate\Rules;

use Viswoole\Core\Exception\ValidateException;

/**
 * 默认验证规则，抽象类
 */
abstract class RuleAbstract
{
  /**
   * @var string 错误提示消息
   */
  protected string $message;

  /**
   * @param string $message 错误提示信息
   */
  public function __construct(string $message = '')
  {
    $this->message = $message;
  }

  /**
   * 验证数据
   *
   * @param mixed $value
   * @return mixed 返回校验后的数据
   * @throws ValidateException 验证失败请抛出异常或调用$this->error()
   */
  abstract public function validate(mixed $value): mixed;

  /**
   * 抛出验证失败异常
   *
   * @param string|null $message 自定义错误提示信息，如果为null则使用$this->message,仅在$this->message为空时有效
   * @return void
   * @throws ValidateException
   */
  protected function error(?string $message = null): void
  {
    throw new ValidateException(empty($this->message) ? ($message ?? '验证失败') : $this->message);
  }
}
