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

use Override;
use Stringable;
use Viswoole\Core\Coroutine;
use Viswoole\Core\Coroutine\Context;
use Viswoole\Log\Contract\DriveInterface;

abstract class Drive extends Collector implements DriveInterface
{
  /**
   * @var string 容器记录名
   */
  protected string $contextName;

  /**
   * @inheritDoc
   */
  #[Override] public function clearRecord(): void
  {
    $this->getRecorder()->clear();
  }

  /**
   * 获取日志记录器
   *
   * @return Recorder
   */
  private function getRecorder(): Recorder
  {
    $key = $this->getContextName();
    if (!Context::has($key)) {
      Context::set($key, new Recorder($this));
    }
    return Context::get($key);
  }

  /**
   * 获取协程上下文记录键
   *
   * @return string
   */
  private function getContextName(): string
  {
    if (!isset($this->contextName)) {
      $className = str_replace('\\', '_', get_called_class());
      $this->contextName = uniqid('$log_recorder_' . $className);
    }
    return $this->contextName;
  }

  /**
   * @inheritDoc
   */
  #[Override] public function getRecord(): array
  {
    return $this->getRecorder()->get();
  }

  /**
   * @inheritDoc
   */
  #[Override] public function mixed(string $level, Stringable|string $message, array $context = []
  ): void
  {
    $this->record($level, $message, $context);
  }

  /**
   * @inheritDoc
   */
  #[Override] public function record(
    string            $level,
    Stringable|string $message,
    array             $context = [],
  ): void
  {
    if (Coroutine::isCoroutine()) {
      $this->getRecorder()->push($level, $message, $context);
    } else {
      $this->write($level, $message, $context);
    }
  }

  /**
   * @inheritDoc
   */
  #[Override] public function write(
    string            $level,
    Stringable|string $message,
    array             $context = [],
  ): void
  {
    $this->save([Manager::createLogData($level, $message, $context)]);
  }
}
