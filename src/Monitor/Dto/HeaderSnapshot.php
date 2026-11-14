<?php

declare(strict_types=1);

/* 职责：以稳定字符串映射表达请求或响应头，将整组脱敏与截断独立呈现。 */

namespace Viswoole\Monitor\Dto;

use InvalidArgumentException;

/** 响应头和请求头的类型化集合；不把 __truncated 状态混入标头映射。 */
final readonly class HeaderSnapshot extends CollectionRecord
{
  /** @var array<string,string> 已脱敏标头名称与值 */
  public array $values;

  /**
   * 构建安全标头集合
   *
   * @param mixed $value 标头映射或整组省略标记
   * @throws InvalidArgumentException 标头字段不属于字符串映射
   */
  public function __construct(mixed $value)
  {
    parent::__construct($value);
    $values = $this->collectionValues();
    foreach ($values as $key => $header) {
      if (!is_string($key) || !is_string($header)) { throw new InvalidArgumentException('快照标头必须是字符串映射'); }
    }
    $this->values = $values;
  }
}
