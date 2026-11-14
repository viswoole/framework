<?php

declare(strict_types=1);

/* 职责：按槽位核算请求内已保留的数据预算，给固定结构与统计预留空间。 */

namespace Viswoole\Monitor;

/**
 * 请求快照共享预算
 *
 * 按 JSON 编码字节控制可变数据总量，不等同于 PHP 堆内存占用。
 * 固定预留 8 KiB 用于字段名、汇总、截断标志和失败结果，避免收尾时膨胀。
 */
final class SnapshotBudget
{
  /** @var array<string,int> 有界保留槽位及字节数 */
  private array $slots = [];
  private int $usedBytes = 0;
  private bool $isTruncated = false;

  /**
   * 创建共享预算
   *
   * @param int $maxBytes 配置验证过的快照总上限
   */
  public function __construct(private readonly int $maxBytes) {}

  /**
   * 替换槽位占用
   *
   * 容量不足时移除旧值，调用方必须同步丢弃对应内容，不能留下未计费数据。
   *
   * @param string $slot 槽位名
   * @param int $bytes 新占用字节，包含该明细预留
   * @return bool 新内容是否允许保留
   */
  public function replace(string $slot, int $bytes): bool
  {
    $this->release($slot);
    if ($this->usedBytes + $bytes > $this->maxBytes - 8192) {
      $this->isTruncated = true;
      return false;
    }
    $this->slots[$slot] = $bytes;
    $this->usedBytes += $bytes;
    return true;
  }

  /**
   * 释放槽位
   *
   * @param string $slot 不再保留的数据槽位
   * @return void
   */
  public function release(string $slot): void
  {
    $this->usedBytes -= $this->slots[$slot] ?? 0;
    unset($this->slots[$slot]);
  }

  /**
   * 查询预算截断状态
   *
   * @return bool 曾拒绝数据时为 true
   */
  public function hasTruncated(): bool
  {
    return $this->isTruncated;
  }

  /**
   * 计算安全数据的编码成本
   *
   * @param mixed $value 已脱敏且有局部上限的数据
   * @return int 不转义 Unicode 和斜线的 JSON 字节数
   */
  public static function size(mixed $value): int
  {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    // 输入已经脱敏器限定为有限标量树，编码失败属防御兜底；strict_types 下
    // strlen(false) 会抛 TypeError，按 0 计费保持预算核算可用
    return $encoded === false ? 0 : strlen($encoded);
  }
}
