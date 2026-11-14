<?php

declare(strict_types=1);

namespace Viswoole\HttpServer\Response;

use InvalidArgumentException;

/** 职责：构建单条 SSE 帧，字段校验在任何实际写入之前完成。 */
final class EventStreamFrame
{
  /**
   * 编码一条 SSE 事件
   * @param string $data 业务数据，可包含任意换行
   * @param string|null $event 单行事件类型
   * @param string|null $id 不含 NUL 的单行事件标识
   * @return string 完整 SSE 帧
   * @throws InvalidArgumentException 事件元数据包含非法分隔符
   */
  public static function encode(string $data, ?string $event = null, ?string $id = null): string
  {
    self::validateField($event, false);
    self::validateField($id, true);
    $frame = $id === null ? '' : "id: {$id}\n";
    if ($event !== null) { $frame .= "event: {$event}\n"; }
    // SSE 把单独 CR 也视为换行；必须在加 data 前缀之前统一处理。
    $data = str_replace(["\r\n", "\r"], "\n", $data);
    foreach (explode("\n", $data) as $line) {
      $frame .= "data: {$line}\n";
    }
    return $frame . "\n";
  }

  /**
   * 校验元数据不能注入新字段
   * @param string|null $value 字段值
   * @param bool $isIdentifier 是否额外校验 ID 的 NUL 限制
   * @return void
   */
  private static function validateField(?string $value, bool $isIdentifier): void
  {
    if ($value === null) { return; }
    if (str_contains($value, "\r") || str_contains($value, "\n") || ($isIdentifier && str_contains($value, "\0"))) {
      throw new InvalidArgumentException('SSE event/id 不允许换行，id 还不能包含 NUL 字符');
    }
  }
}
