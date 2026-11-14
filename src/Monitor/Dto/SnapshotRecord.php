<?php

declare(strict_types=1);

/* 职责：保留嵌套记录的 JSON 字段和不可变值边界；仅承载已脱敏的定稿数据，不保存请求或连接等资源。 */

namespace Viswoole\Monitor\Dto;

use InvalidArgumentException;
use JsonSerializable;

/**
 * 不可变嵌套记录基类
 *
 * 复制数组并拒绝对象与资源，避免 readonly 属性内部仍保留可变对象或引用。
 * 保留原字段及缺失语义，toArray 不补充未采集字段，兼容已有 JSON 契约。
 */
abstract readonly class SnapshotRecord implements JsonSerializable
{
  private array $data;

  /**
   * 复制安全记录
   *
   * @param array<string,mixed> $data 已脱敏、有界且可 JSON 编码的数据
   * @throws InvalidArgumentException 数据包含对象、资源、非法数值或过深结构
   */
  public function __construct(array $data)
  {
    $this->data = self::copy($data);
  }

  /**
   * 输出原始字段契约
   *
   * @return array<string,mixed> 独立数组，不暴露内部引用
   */
  public function toArray(): array
  {
    return $this->data;
  }

  /**
   * 输出 JSON 数据
   *
   * @return array<string,mixed> 与 toArray 完全一致的字段
   */
  public function jsonSerialize(): array
  {
    return $this->toArray();
  }

  /**
   * 复制嵌套标量树
   *
   * @param array $data 源数组
   * @param int $depth 当前深度
   * @return array 无对象、资源与外部引用的数组
   * @throws InvalidArgumentException 数据不属于有界 JSON 值树
   */
  private static function copy(array $data, int $depth = 0): array
  {
    if ($depth > 16) { throw new InvalidArgumentException('快照结构超过允许深度'); }
    $result = [];
    foreach ($data as $key => $value) {
      if (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
        throw new InvalidArgumentException('快照只能包含有限标量、数组和 null');
      }
      $result[$key] = is_array($value) ? self::copy($value, $depth + 1) : $value;
    }
    return $result;
  }
}
