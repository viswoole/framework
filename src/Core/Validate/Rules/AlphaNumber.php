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

/**
 * 只能由字母或数字组成
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class AlphaNumber extends Regex
{
  /**
   * @param string $message 校验失败信息
   */
  public function __construct(string $message = '只能由字母或数字组成')
  {
    parent::__construct('/^[A-Za-z0-9]+$/', $message);
  }
}
