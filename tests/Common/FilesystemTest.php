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

namespace Viswoole\Tests\Common;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\Common\Filesystem;
use Viswoole\Core\Exception\FilesystemException;

/**
 * 文件系统工具类测试
 */
class FilesystemTest extends TestCase
{
  /** @var string 测试用临时目录根路径 */
  private string $tmpRoot;

  /**
   * 每个 测试用例 前创建独立的临时目录，避免用例间相互影响
   *
   * @return void
   */
  protected function setUp(): void
  {
    $this->tmpRoot = sys_get_temp_dir() . '/viswoole_filesystem_test_' . uniqid();
  }

  /**
   * 每个 测试用例 后清理临时目录
   *
   * @return void
   */
  protected function tearDown(): void
  {
    if (is_dir($this->tmpRoot)) {
      // 递归删除测试产生的临时文件与目录
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
      rmdir($this->tmpRoot);
    }
  }

  /**
   * 测试创建多级嵌套目录
   *
   * @return void
   */
  public function testEnsureDirectoryCreatesNestedDirectories(): void
  {
    $dir = $this->tmpRoot . '/a/b/c';
    Filesystem::ensureDirectory($dir);
    static::assertDirectoryExists($dir);
  }

  /**
   * 测试目录已存在时不重复创建且不抛异常
   *
   * @return void
   */
  public function testEnsureDirectoryWithExistingDirectory(): void
  {
    $dir = $this->tmpRoot . '/exists';
    mkdir($dir, 0755, true);
    Filesystem::ensureDirectory($dir);
    static::assertDirectoryExists($dir);
  }

  /**
   * 测试真实并发：多协程同时创建同一目录，全部协程均应成功返回
   *
   * @return void
   */
  public function testEnsureDirectoryWithConcurrentCoroutines(): void
  {
    $dir = $this->tmpRoot . '/concurrent';
    // 在协程环境中模拟多 worker 并发创建目录
    \Swoole\Coroutine\run(function () use ($dir) {
      $results = [];
      $wg = new \Swoole\Coroutine\WaitGroup();
      for ($i = 0; $i < 10; $i++) {
        $wg->add();
        \Swoole\Coroutine::create(function () use ($wg, $dir, &$results, $i) {
          try {
            Filesystem::ensureDirectory($dir);
            $results[$i] = true;
          } catch (\Throwable) {
            $results[$i] = false;
          } finally {
            $wg->done();
          }
        });
      }
      $wg->wait();
      static::assertDirectoryExists($dir);
      // 协程完成顺序不定导致 $results 键序不确定，使用顺序无关断言
      static::assertCount(10, $results);
      static::assertNotContains(false, $results);
    });
  }

  /**
   * 测试创建失败时抛出 FilesystemException（路径被同名文件占用）
   *
   * @return void
   */
  public function testEnsureDirectoryThrowsWhenCreationFails(): void
  {
    // 用同名文件占用目标路径，导致 mkdir 必定失败
    mkdir($this->tmpRoot, 0755, true);
    $file = $this->tmpRoot . '/blocked';
    touch($file);
    try {
      Filesystem::ensureDirectory($file . '/sub');
      static::fail('预期抛出 FilesystemException');
    } catch (FilesystemException $e) {
      // 异常消息应包含目录路径与底层真实错误（mkdir(): File exists），便于定位问题
      static::assertStringContainsString('创建目录失败', $e->getMessage());
      static::assertStringContainsString($file . '/sub', $e->getMessage());
      static::assertStringContainsString('mkdir():', $e->getMessage());
    }
  }
}
