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
 * 身份证号验证规则，仅支持 18 位格式，含校验位算法验证
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class IdCard extends BaseValidateRule
{
  /**
   * 18位身份证正则（含校验位 [0-9Xx]）
   */
  private const string PATTERN_18 = '/^[1-9]\d{5}(18|19|([23]\d))\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}[0-9Xx]$/';

  /**
   * 校验码加权因子，对应前17位的位置权重
   */
  private const array WEIGHT_FACTORS = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];

  /**
   * 校验码对照表，余数 → 校验码
   */
  private const string CHECK_CODES = '10X98765432';

  /**
   * @param string $message 校验失败信息
   */
  public function __construct(string $message = '{:name} 必须是有效的身份证号码')
  {
    parent::__construct($message);
  }

  /**
   * 校验身份证号格式与校验位
   */
  #[Override] public function validate(mixed $value): mixed
  {
    if (!is_string($value)) $this->error('{:name} 必须为字符串类型');

    // 先用正则验证基本格式
    if (!preg_match(self::PATTERN_18, $value)) {
      $this->error();
    }

    // 校验位验证
    if (!$this->verifyCheckCode($value)) {
      $this->error();
    }

    return $value;
  }

  /**
   * 验证18位身份证的校验位
   * 根据前17位数字和加权因子计算校验码，与第18位比对
   *
   * @param string $idCard 18位身份证号码
   * @return bool 校验位是否正确
   */
  private function verifyCheckCode(string $idCard): bool
  {
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
      $sum += (int)$idCard[$i] * self::WEIGHT_FACTORS[$i];
    }
    $checkCode = self::CHECK_CODES[$sum % 11];
    return strtoupper($idCard[17]) === $checkCode;
  }
}
