<?php

declare(strict_types=1);

namespace Viswoole\Tests\Cache;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Viswoole\Cache\Driver\File;

/**
 * 文件缓存键路径穿越防护测试
 *
 * 缓存键常由业务拼接用户输入（如 cache('user_' . $uid)），
 * 若键含 ../ 等穿越序列，get/set/unlink 会落到缓存目录之外，
 * 构成任意路径读写删。期望构建期即拒绝非法键
 */
class FileCacheKeySanitizeTest extends TestCase
{
  /**
   * 测试含路径穿越序列的缓存键被拒绝
   *
   * @return void
   */
  public function testRejectsTraversalKey(): void
  {
    $driver = new File(
      storage: sys_get_temp_dir() . '/viswoole_key_sanitize_' . uniqid(),
      prefix: ''
    );
    $this->expectException(InvalidArgumentException::class);
    $driver->set('user/../../evil_cache', 'data');
  }

  /**
   * 测试含反斜杠的缓存键被拒绝
   *
   * @return void
   */
  public function testRejectsBackslashKey(): void
  {
    $driver = new File(
      storage: sys_get_temp_dir() . '/viswoole_key_sanitize_' . uniqid(),
      prefix: ''
    );
    $this->expectException(InvalidArgumentException::class);
    $driver->set('user\\..\\..\\evil_cache', 'data');
  }
}
