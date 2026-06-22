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

/**
 * 日志驱动抽象基类，提供协程感知的日志记录与写入能力
 *
 * 在协程环境中将日志缓存至协程上下文的 Recorder，协程结束时批量持久化；
 * 非协程环境直接写入。子类只需实现 save() 方法定义持久化策略。
 *
 * @see DriveInterface 驱动接口
 * @see Recorder 协程日志记录器
 */
abstract class Drive extends Collector implements DriveInterface
{
  /**
   * @var string 协程上下文中存储 Recorder 的键名
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
   * 获取当前协程绑定的日志记录器，不存在时自动创建并存入协程上下文
   *
   * @return Recorder 当前协程的日志记录器实例
   */
  private function getRecorder(): Recorder
  {
    $key = $this->getContextName();
    if (Context::has($key)) {
      $recorder = Context::get($key);
    } else {
      $recorder = new Recorder($this);
      Context::set($key, $recorder);
    }
    return $recorder;
  }

  /**
   * 生成协程上下文中存储 Recorder 的唯一键，基于类名避免不同驱动冲突
   *
   * @return string 协程上下文键名
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
  #[Override] public function mixed(
    string            $level,
    Stringable|string $message,
    array             $context = []
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
    $this->save([LogManager::createLogData($level, $message, $context)]);
  }
}
