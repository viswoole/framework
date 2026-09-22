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
use Viswoole\Core\Exception\ValidateException;
use Viswoole\Core\Validate;
use Viswoole\Core\Validate\BaseValidateRule;
use Viswoole\Core\Validate\Type;

/**
 * 映射参数验证规则：声明「允许的键集合 + 各键值类型约束」
 *
 * 适用于 {"goodsId": "123", "num": "5"} 这类扁平映射参数——逐键执行类型校验
 * 并返回校验后的映射（键名保留），防止注入层之外出现未声明的键。
 *
 * 与 ArrayItem 的分工：ArrayItem 用于纯列表（逐元素同类型约束，如 [1,2,3]），
 * MapItem 用于键值映射（每键独立类型约束，如 {"goodsId":"123"}）。
 *
 * 返回值仅包含声明键的校验后值；allowUnknown=true 时未声明键被静默丢弃，
 * 不进入返回值（避免未校验数据流入业务层）。
 *
 * 用法示例：
 * ```
 * #[InjectPost, MapItem(['goodsId' => 'int', 'num' => 'int'])] ?array $linkParams
 * ```
 * ⚠️ 键约束必须以位置数组参数声明——PHP 注解的命名参数须匹配构造器形参，
 * 键名无法作为命名参数使用。
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class MapItem extends BaseValidateRule
{
  /**
   * @param array<string, string|Type> $fields 键名 => 值类型约束（内置类型名、
   *   管道符联合类型字符串如 'int|string'，或 Type 枚举）
   * @param bool $allowUnknown 是否允许声明之外的键；false（默认）= 遇未知键报错
   * @param bool $allowMissing 是否允许声明键缺失；true（默认）= 缺键跳过，
   *   false = 缺键报错
   * @param string $message 校验失败提示信息
   */
  public function __construct(
    protected array  $fields,
    protected bool   $allowUnknown = false,
    protected bool   $allowMissing = true,
    string           $message = ''
  )
  {
    parent::__construct($message);
  }

  /**
   * 逐键执行类型校验，返回仅含声明键的校验后映射（键名保留）
   */
  #[Override] public function validate(mixed $value): array
  {
    if (!is_array($value)) $this->error('{:name} 必须为数组');
    $result = [];
    foreach ($this->fields as $key => $type) {
      if (!array_key_exists($key, $value)) {
        if (!$this->allowMissing) $this->error('{:name} 缺少声明键 ' . $key);
        continue;
      }
      try {
        $result[$key] = Validate::check($value[$key], $type);
      } catch (ValidateException $e) {
        $this->error('{:name}.' . $key . ': ' . $e->getMessage());
      }
    }
    if (!$this->allowUnknown) {
      foreach ($value as $key => $_) {
        if (!array_key_exists($key, $this->fields)) {
          $this->error('{:name} 含有未声明的键 ' . $key);
        }
      }
    }
    return $result;
  }
}
