<?php

declare(strict_types=1);

/* 职责：验证清扫遇到不完整证明、未知文件和符号链接时保持保守。 */

namespace Viswoole\Tests\Monitor;

use PHPUnit\Framework\TestCase;
use Viswoole\Monitor\Runtime\LockRegistry;

/** 所有异常目录均位于本测试创建的私有根目录中，不触碰用户临时文件。 */
final class LockRegistrySafetyTest extends TestCase
{
  private string $root;
  private LockRegistry $registry;

  /** @return void 创建隔离目录 */
  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/viswoole-registry-test-' . bin2hex(random_bytes(12));
    mkdir($this->root, 0700);
    $this->registry = new LockRegistry($this->root);
  }

  /** @return void 清理固定控制文件及隔离根目录 */
  protected function tearDown(): void
  {
    if (is_file($this->root . '/registry.lock')) { unlink($this->root . '/registry.lock'); }
    rmdir($this->root);
  }

  /** @return void 缺少租约但存在互斥文件时无法证明无活跃使用者，必须保留 */
  public function testMissingLeaseCannotAuthorizeDeletingMutex(): void
  {
    $directory = $this->root . '/instance-' . str_repeat('a', 32);
    mkdir($directory, 0700);
    file_put_contents($directory . '/mutex.lock', '');
    try {
      self::assertSame(0, $this->registry->sweep());
      self::assertFileExists($directory . '/mutex.lock');
    } finally { unlink($directory . '/mutex.lock'); rmdir($directory); }
  }

  /** @return void 未知文件会阻止整目录清扫，不能顺手删除其他数据 */
  public function testUnknownFilesAreNotRemoved(): void
  {
    $lease = $this->registry->create();
    $directory = $lease->directory;
    $lease->close();
    file_put_contents($directory . '/unexpected', 'keep');
    try {
      self::assertSame(0, $this->registry->sweep());
      self::assertFileExists($directory . '/mutex.lock');
      self::assertSame('keep', file_get_contents($directory . '/unexpected'));
    } finally { unlink($directory . '/unexpected'); $this->registry->sweep(); }
    self::assertDirectoryDoesNotExist($directory);
  }

  /** @return void 注册锁排除初始化中的创建者后，空的半初始化目录可安全清除 */
  public function testEmptyInterruptedInitializationIsSwept(): void
  {
    $directory = $this->root . '/instance-' . str_repeat('b', 32);
    mkdir($directory, 0700);
    self::assertSame(1, $this->registry->sweep());
    self::assertDirectoryDoesNotExist($directory);
  }

  /** @return void 实例目录符号链接不能导致越界清扫 */
  public function testSymlinkCannotRedirectCleanup(): void
  {
    $outside = $this->root . '/outside';
    $link = $this->root . '/instance-' . str_repeat('c', 32);
    mkdir($outside, 0700);
    file_put_contents($outside . '/mutex.lock', 'keep');
    symlink($outside, $link);
    try {
      self::assertSame(0, $this->registry->sweep());
      self::assertSame('keep', file_get_contents($outside . '/mutex.lock'));
    } finally { unlink($link); unlink($outside . '/mutex.lock'); rmdir($outside); }
  }
}
