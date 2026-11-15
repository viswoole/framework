<?php

declare(strict_types=1);

namespace Viswoole\Tests\Cache;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Cache\Driver\File;
use Viswoole\Cache\Exception\CacheErrorException;

/** 验证内部锁区域的权限边界、缓存键隔离和清理行为。 */
final class FileReservedStorageTest extends TestCase
{
  private string $root;
  private string $storage;
  private File $cache;

  protected function setUp(): void
  {
    $this->root = sys_get_temp_dir() . '/viswoole-reserved-' . bin2hex(random_bytes(8));
    $this->storage = $this->root . '/cache';
    mkdir($this->storage, 0777, true);
    $this->cache = new File($this->storage);
  }

  protected function tearDown(): void
  {
    chmod($this->root, 0755);
    $this->cache->close();
    $this->cache->clear();
    $directory = $this->storage . '/' . File::LOCK_DIRECTORY;
    if (is_dir($directory)) {
      foreach (glob($directory . '/*') as $filename) unlink($filename);
      rmdir($directory);
    }
    rmdir($this->storage);
    rmdir($this->root);
  }

  public function testOnlyCacheDirectoryMustBeWritable(): void
  {
    chmod($this->root, 0555);
    self::assertTrue($this->cache->set('value', 'cached'));
    $id = $this->cache->lock('permission', expire: 0, autoUnlock: true);
    self::assertTrue($this->cache->clear());
    self::assertNull($this->cache->get('value'));
    $competitor = new File($this->storage);
    try {
      $competitor->lock('permission', retry: 1);
      self::fail('清缓存不能破坏仍在使用的租约');
    } catch (CacheErrorException $exception) {
      self::assertSame('缓存系统繁忙，请稍后重试', $exception->getMessage());
    }
    self::assertTrue($this->cache->unlock($id));
    $next = $competitor->lock('permission', retry: 1);
    self::assertTrue($competitor->unlock($next));
  }

  public function testCacheOperationsCannotAccessReservedRegion(): void
  {
    $id = $this->cache->lock('protected', autoUnlock: true);
    $record = hash('sha256', 'lock_protected') . '.json';
    $keys = [File::LOCK_DIRECTORY, File::LOCK_DIRECTORY . '/' . $record,
      './' . File::LOCK_DIRECTORY . '//' . $record, strtoupper(File::LOCK_DIRECTORY) . '/' . $record];
    foreach ($keys as $key) {
      foreach (['get', 'set', 'delete', 'has', 'pull', 'ttl'] as $operation) {
        $this->assertReservedKeyRejected(['cache' => $this->cache, 'key' => $key, 'operation' => $operation]);
      }
    }
    $prefixed = new File($this->storage, prefix: File::LOCK_DIRECTORY . '/');
    $this->assertReservedKeyRejected(['cache' => $prefixed, 'key' => $record, 'operation' => 'set']);
    self::assertTrue($this->cache->unlock($id));
  }

  public function testDeletingLastCacheFilePreservesHiddenLockRegion(): void
  {
    $id = $this->cache->lock('delete', autoUnlock: true);
    self::assertTrue($this->cache->set('only', 'cached'));
    self::assertSame(1, $this->cache->delete('only'));
    self::assertDirectoryExists($this->storage . '/' . File::LOCK_DIRECTORY);
    self::assertTrue($this->cache->clear());
    self::assertTrue($this->cache->unlock($id));
  }

  /**
   * 缓存键及前缀都不能访问锁区域，避免绕过租约持有者校验。
   *
   * @param array{cache:File,key:string,operation:string} $options 缓存操作
   * @return void
   */
  private function assertReservedKeyRejected(array $options): void
  {
    try {
      $cache = $options['cache'];
      $operation = $options['operation'];
      if ($operation === 'set') $cache->set($options['key'], 'overwrite');
      else $cache->{$operation}($options['key']);
      self::fail('缓存操作不应访问内部锁区域');
    } catch (InvalidArgumentException $exception) {
      self::assertStringContainsString('内部锁目录', $exception->getMessage());
    }
  }
}
