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

use Attribute;
use Override;
use Viswoole\Core\Validate\BaseValidateRule;

/**
 * PHP 内置过滤器验证规则，基于 filter_var 实现常见格式校验
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Filter extends BaseValidateRule
{
  /**
   * 支持的验证过滤器及其默认错误提示
   */
  const array FILTER_VALIDATE = [
    FILTER_VALIDATE_INT => '必须是INT类型',
    FILTER_VALIDATE_BOOL => '必须是BOOL类',
    FILTER_VALIDATE_FLOAT => '必须是浮点类型',
    FILTER_VALIDATE_REGEXP => '必须是有效的正则表达式',
    FILTER_VALIDATE_DOMAIN => '必须是有效的域名',
    FILTER_VALIDATE_URL => '必须是有效的URL地址',
    FILTER_VALIDATE_EMAIL => '必须是有效的邮箱',
    FILTER_VALIDATE_IP => '必须是合法的IP地址',
    FILTER_VALIDATE_MAC => '必须是有效的MAC地址'
  ];

  /**
   * @param int $filter 过滤器ID，参考 PHP filter_var() 的 filter 参数
   * @param array|int $options 过滤选项或标志位
   * @param string $message 校验失败提示信息，为空时使用过滤器默认提示
   * @see filter_var()
   * @link https://www.php.net/manual/zh/filter.filters.validate.php
   */
  public function __construct(
    public int       $filter,
    public array|int $options = 0,
    string           $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 使用 filter_var 执行校验，验证失败时抛出异常
   */
  #[Override] public function validate(mixed $value): mixed
  {
    $valid = filter_var($value, $this->filter, $this->options);
    // 修复: filter_var 可能返回合法的假值(如 0, false, 0.0)，应使用严格比较判断失败
    // FILTER_VALIDATE_BOOL 失败时返回 null，其他过滤器失败时返回 false
    if ($valid === false || $valid === null) {
      $this->error('{:name} ' . (self::FILTER_VALIDATE[$this->filter] ?? '验证失败'));
    }
    if (!in_array($this->filter, array_keys(self::FILTER_VALIDATE))) return $valid;
    return $value;
  }
}
