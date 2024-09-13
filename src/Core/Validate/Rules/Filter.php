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
 * filter_var过滤器验证
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Filter extends BaseValidateRule
{
  /**
   * 支持的验证过滤器
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
   * @param int $filter 过滤器ID，参考filter_var()方法filter参数
   * @param array|int $options 过滤选项
   * @param string $message 不传则会使用默认的错误提示信息
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
   * @inheritDoc
   */
  #[Override] public function validate(mixed $value): mixed
  {
    $valid = filter_var($value, $this->filter, $this->options);
    if (!$valid) $this->error(self::FILTER_VALIDATE[$this->filter] ?? '验证失败');
    if (!in_array($this->filter, array_keys(self::FILTER_VALIDATE))) return $valid;
    return $value;
  }
}
