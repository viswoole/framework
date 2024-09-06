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

namespace Viswoole\Log;

use Stringable;
use Viswoole\Log\Contract\CollectorInterface;

/**
 * 日志收集器抽象类
 */
abstract class Collector implements CollectorInterface
{

  /**
   * @inheritDoc
   */
  #[\Override] public function error(Stringable|string $message, array $context = []): void
  {
    $this->mixed('error', $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function warning(Stringable|string $message, array $context = []): void
  {
    $this->mixed('warning', $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function info(Stringable|string $message, array $context = []): void
  {
    $this->mixed('info', $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function debug(Stringable|string $message, array $context = []): void
  {
    $this->mixed('debug', $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function sql(Stringable|string $message, array $context = []): void
  {
    $this->mixed('sql', $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function task(Stringable|string $message, array $context = []): void
  {
    $this->mixed('task', $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[\Override] public function alert(Stringable|string $message, array $context = []): void
  {
    $this->mixed('alert', $message, $context);
  }
}
