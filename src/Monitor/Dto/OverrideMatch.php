<?php

declare(strict_types=1);

/* 职责：向 writer 提供单条命中规则的稳定身份。 */

namespace Viswoole\Monitor\Dto;

/** 不携带项目业务配置，也不读取当前规则。 */
final readonly class OverrideMatch extends SnapshotRecord
{
  public string $id;
  public int $version;
  public float $thresholdMs;

  /**
   * 构建命中身份
   * @param array{id:string,version:int,threshold_ms:float} $data 已固定的规则来源
   */
  public function __construct(array $data)
  {
    parent::__construct($data);
    $this->id = $data['id'];
    $this->version = $data['version'];
    $this->thresholdMs = (float)$data['threshold_ms'];
  }
}
