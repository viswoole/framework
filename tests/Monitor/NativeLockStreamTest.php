<?php

declare(strict_types=1);

/* 职责：验证锁文件原生流边界与 Hook 的异常恢复，不涉及配置编解码。 */

namespace Viswoole\Tests\Monitor;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Viswoole\Monitor\Runtime\NativeLockStream;

final class NativeLockStreamTest extends TestCase
{
  /** @return void FILE Hook 已开启时仍创建原生流，且每次操作都恢复设置 */
  public function testFileHookDoesNotDetermineLockStreamType(): void
  {
    $originalFlags = Runtime::getHookFlags();
    $path = sys_get_temp_dir() . '/viswoole-native-lock-' . bin2hex(random_bytes(8));
    $handle = null;
    try {
      Runtime::setHookFlags(SWOOLE_HOOK_ALL);
      $handle = NativeLockStream::open($path, 'x+');
      self::assertSame(SWOOLE_HOOK_ALL, Runtime::getHookFlags());
      // 原生操作表会随全局 STDIO Hook 改名；关闭 Hook 后才可区分独立的协程流。
      Runtime::setHookFlags(SWOOLE_HOOK_ALL & ~(SWOOLE_HOOK_FILE | SWOOLE_HOOK_STDIO));
      self::assertSame('STDIO', stream_get_meta_data($handle)['stream_type']);
      Runtime::setHookFlags(SWOOLE_HOOK_ALL);
      self::assertTrue(NativeLockStream::lock($handle, LOCK_EX | LOCK_NB));
      self::assertSame(SWOOLE_HOOK_ALL, Runtime::getHookFlags());
      self::assertTrue(NativeLockStream::lock($handle, LOCK_UN));
      NativeLockStream::close($handle);
      self::assertSame(SWOOLE_HOOK_ALL, Runtime::getHookFlags());
    } finally {
      if (is_resource($handle)) { NativeLockStream::close($handle); }
      if (is_file($path)) { unlink($path); }
      Runtime::setHookFlags($originalFlags);
    }
  }

  /** @return void 文件错误保留原始原因，且恢复 Hook 与外部错误处理器 */
  public function testFailedOpenRestoresHookFlags(): void
  {
    $originalFlags = Runtime::getHookFlags();
    Runtime::setHookFlags(SWOOLE_HOOK_ALL);
    set_error_handler(static function (int $severity, string $message): never {
      throw new ErrorException($message, severity: $severity);
    });
    try {
      try {
        NativeLockStream::open('/tmp/nonexistent-' . bin2hex(random_bytes(8)) . '/lease.lock', 'r+');
        self::fail('文件打开失败必须抛出异常');
      } catch (\RuntimeException $error) {
        self::assertInstanceOf(ErrorException::class, $error->getPrevious());
        self::assertStringContainsString('fopen(', $error->getMessage());
      }
      $this->expectException(ErrorException::class);
      trigger_error('验证外部错误处理器已恢复', E_USER_WARNING);
    } finally {
      restore_error_handler();
      self::assertSame(SWOOLE_HOOK_ALL, Runtime::getHookFlags());
      Runtime::setHookFlags($originalFlags);
    }
  }

  /** @return void 外部错误处理器即使会让出协程，也不能进入 Hook 隔离区 */
  public function testYieldingErrorHandlerCannotExposeDisabledHooks(): void
  {
    $originalFlags = Runtime::getHookFlags();
    Runtime::setHookFlags(SWOOLE_HOOK_ALL);
    $isHandlerCalled = false;
    $observedFlags = null;
    try {
      Coroutine\run(static function () use (&$isHandlerCalled, &$observedFlags): void {
        set_error_handler(static function () use (&$isHandlerCalled): bool {
          $isHandlerCalled = true;
          Coroutine::sleep(0.01);
          return true;
        });
        try {
          Coroutine::create(static function () use (&$observedFlags): void {
            Coroutine::sleep(0.001);
            $observedFlags = Runtime::getHookFlags();
          });
          try {
            NativeLockStream::open('/tmp/nonexistent-' . bin2hex(random_bytes(8)) . '/lease.lock', 'r+');
            self::fail('文件打开失败必须抛出异常');
          } catch (\RuntimeException $error) {
            self::assertStringContainsString('lease.lock', $error->getMessage());
          }
        } finally { restore_error_handler(); }
      });
      self::assertFalse($isHandlerCalled);
      self::assertSame(SWOOLE_HOOK_ALL, $observedFlags);
    } finally { Runtime::setHookFlags($originalFlags); }
  }

  /** @return void 同步锁文件入口拒绝阻塞式获取，防止冻结协程调度 */
  public function testBlockingLockOperationIsRejected(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    NativeLockStream::lock(null, LOCK_EX);
  }
}
