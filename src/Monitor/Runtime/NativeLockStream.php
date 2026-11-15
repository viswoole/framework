<?php

declare(strict_types=1);

/* 职责：让监控锁文件始终使用原生文件流；只隔离短同步操作，不承载等待或业务回调。 */

namespace Viswoole\Monitor\Runtime;

use ErrorException;
use RuntimeException;
use Swoole\Runtime;

/** 创建与关闭都隔离 Hook，避免 fork 继承句柄被协程流的 close 显式解锁。 */
final class NativeLockStream
{
  /**
   * 打开原生锁文件，避免 FILE Hook 创建独立的 STDIO/coroutine 操作表
   * @param string $path 锁文件路径
   * @param string $mode 文件打开模式
   * @return resource 原生文件句柄
   * @throws RuntimeException 打开失败
   */
  public static function open(string $path, string $mode): mixed
  {
    $handle = self::run(static fn (): mixed => fopen($path, $mode));
    if ($handle === false) { throw new RuntimeException('监控锁文件无法打开: ' . $path); }
    return $handle;
  }

  /**
   * 原生非阻塞加锁或解锁，不能让出持有配置锁的协程
   * @param resource $handle 本类创建的句柄
   * @param int $operation LOCK_SH/LOCK_EX 必须携带 LOCK_NB，或使用 LOCK_UN
   * @return bool 内核操作是否成功
   */
  public static function lock(mixed $handle, int $operation): bool
  {
    if ($operation !== LOCK_UN && ($operation & LOCK_NB) === 0) {
      throw new \InvalidArgumentException('监控锁仅允许非阻塞获取');
    }
    return self::run(static fn (): bool => flock($handle, $operation));
  }

  /**
   * 只关闭当前进程引用，不显式解锁 fork 继承者共享的文件描述
   * @param resource $handle 本类创建的句柄
   * @return void
   * @throws RuntimeException 关闭失败
   */
  public static function close(mixed $handle): void
  {
    if (!self::run(static fn (): bool => fclose($handle))) {
      throw new RuntimeException('监控锁句柄关闭失败');
    }
  }

  /**
   * 在无协程切换的同步调用内隔离文件 Hook，并在异常时恢复原设置
   * @template TResult
   * @param callable():TResult $operation 仅限本类原生 fopen/flock/fclose
   * @return TResult 操作结果
   * @throws RuntimeException Hook 设置或原生文件操作失败，保留底层错误原因
   */
  private static function run(callable $operation): mixed
  {
    $flags = Runtime::getHookFlags();
    $nativeFlags = $flags & ~(SWOOLE_HOOK_FILE | SWOOLE_HOOK_STDIO);
    $hasFileHooks = $nativeFlags !== $flags;
    $failure = null;
    // 原生调用也会触发用户错误处理器；在隔离区内只捕获，禁止外部日志或回调让出协程。
    set_error_handler(static function (int $severity, string $message) use (&$failure): bool {
      $failure ??= new ErrorException($message, severity: $severity);
      return true;
    });
    try {
      // 关闭时才移除 STDIO 不足以改变已经由 FILE Hook 创建的流类型。
      if ($hasFileHooks && !Runtime::setHookFlags($nativeFlags)) { throw new RuntimeException('监控锁文件 Hook 隔离失败'); }
      $result = $operation();
    } finally {
      try {
        if ($hasFileHooks && !Runtime::setHookFlags($flags)) { throw new RuntimeException('监控锁文件 Hook 恢复失败'); }
      } finally { restore_error_handler(); }
    }
    if ($failure !== null) { throw new RuntimeException('监控锁文件操作失败: ' . $failure->getMessage(), previous: $failure); }
    return $result;
  }
}
