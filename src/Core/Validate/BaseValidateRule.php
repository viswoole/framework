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

namespace Viswoole\Core\Validate;

use Viswoole\Core\Exception\ValidateException;

/**
 * 扩展验证规则基类
 *
 * 自定义验证规则须继承此类并实现 validate() 方法，
 * 校验失败时调用 error() 抛出 ValidateException。
 * 作为 PHP Attribute 使用时，容器注入参数会自动调用 validate() 进行校验。
 */
abstract class BaseValidateRule
{
  /**
   * @var string 自定义校验失败提示消息，支持 {:name} 占位符引用参数名
   */
  protected string $message;

  /**
   * @param string $message 校验失败时的自定义提示消息，{:name} 会被替换为参数名
   */
  public function __construct(string $message = '')
  {
    $this->message = $message;
  }

  /**
   * 执行校验逻辑，子类必须实现
   *
   * @param mixed $value 待校验的值
   * @return mixed 校验通过的值（可能被转换）
   * @throws ValidateException 校验失败时抛出
   */
  abstract public function validate(mixed $value): mixed;

  /**
   * 抛出校验失败异常，优先使用构造时传入的自定义消息
   *
   * 默认文案通过 {:name} 占位符引用参数名，由 Validate::withContext 统一替换；
   * 自定义消息同样支持 {:name} 占位符，未使用占位符时保持原样
   *
   * @param string|null $message 临时错误消息，仅在 $this->message 为空时生效
   * @throws ValidateException 始终抛出
   */
  protected function error(?string $message = null): void
  {
    throw new ValidateException(
      empty($this->message) ? ($message ?? '{:name} 验证失败') : $this->message
    );
  }
}
