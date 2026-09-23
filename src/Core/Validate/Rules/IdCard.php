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
use DateTimeImmutable;
use Override;
use Viswoole\Core\Validate\BaseValidateRule;

/**
 * 身份证号验证规则，仅支持 18 位格式：格式 + 出生日期段真实 + ISO 7064 MOD 11-2 校验码核验
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class IdCard extends BaseValidateRule
{
  /** 加权因子（ISO 7064 MOD 11-2，第 i 位对应权重 Wi = 2^(18-i) mod 11） */
  private const array WEIGHTS = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];

  /** 校验码映射（余数 → 校验位字符） */
  private const string CHECK_CODES = '10X98765432';

  /**
   * @param string $message 校验失败时的统一自定义提示（留空则按失败原因分别提示）
   */
  public function __construct(string $message = '')
  {
    parent::__construct($message);
  }

  /**
   * 校验身份证号：18 位格式 + 出生日期段真实 + 校验码核验
   *
   * @param mixed $value 待校验的身份证号
   * @return string 校验通过的规范形态（末位大写 X）
   */
  #[Override]
  public function validate(mixed $value): string
  {
    if (!is_string($value)) {
      $this->error('身份证号必须为字符串');
    }

    // 规范形态：去首尾空白 + 末位小写 x 统一大写
    $value = strtoupper(trim($value));

    if (preg_match('/^\d{17}[\dX]$/', $value) !== 1) {
      $this->error('身份证号格式不正确（须为 18 位居民身份证号）');
    }

    if (!self::isBirthDateValid($value)) {
      $this->error('身份证号出生日期不正确');
    }

    if (!self::isCheckCodeValid($value)) {
      $this->error('身份证号校验位不正确');
    }

    return $value;
  }

  /**
   * 出生日期段校验（纯函数）：第 7-14 位须为真实日期且不晚于今天
   *
   * @param string $idCard 18 位身份证号（已规范大写）
   * @return bool true=出生日期合法
   */
  private static function isBirthDateValid(string $idCard): bool
  {
    $birth = substr($idCard, 6, 8);
    $parsed = date_create_from_format('Ymd|', $birth);
    if ($parsed === false) {
      return false;
    }
    // date_create_from_format 对 20260230 类假日期会滚动进位，须回读比对
    if ($parsed->format('Ymd') !== $birth) {
      return false;
    }
    return $parsed >= date_create_from_format('Ymd|', '19000101')
      && $parsed <= new DateTimeImmutable('today');
  }

  /**
   * ISO 7064 MOD 11-2 校验码核验（纯函数）
   *
   * 前 17 位加权求和 mod 11，映射 CHECK_CODES 后与第 18 位比对。
   *
   * @param string $idCard 18 位身份证号（已规范大写）
   * @return bool true=校验码正确
   */
  private static function isCheckCodeValid(string $idCard): bool
  {
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
      $sum += (int)$idCard[$i] * self::WEIGHTS[$i];
    }
    return self::CHECK_CODES[$sum % 11] === $idCard[17];
  }
}
