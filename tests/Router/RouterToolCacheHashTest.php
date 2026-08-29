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

declare(strict_types=1);

namespace Viswoole\Tests\Router;

use PHPUnit\Framework\TestCase;
use Viswoole\Core\App;
use Viswoole\Router\RouterTool;

/**
 * 路由缓存哈希测试
 *
 * 验证 getCacheHash 掺入框架版本号：控制器文件变更或框架升级均使缓存失效
 */
class RouterToolCacheHashTest extends TestCase
{
  /**
   * @var string 测试用临时控制器文件
   */
  private string $tempFile;

  /**
   * 创建临时控制器文件
   */
  protected function setUp(): void
  {
    App::factory();
    $this->tempFile = sys_get_temp_dir() . '/router_cache_hash_' . uniqid() . '.php';
    file_put_contents($this->tempFile, "<?php\nclass A {}\n");
  }

  /**
   * 移除临时文件
   */
  protected function tearDown(): void
  {
    @unlink($this->tempFile);
  }

  /**
   * 测试同一文件多次计算哈希结果稳定
   *
   * @return void
   */
  public function testHashIsDeterministic(): void
  {
    $first = RouterTool::getCacheHash($this->tempFile);
    $second = RouterTool::getCacheHash($this->tempFile);
    static::assertSame($first, $second);
  }

  /**
   * 测试哈希掺入了框架版本号（区别于纯文件内容哈希）
   *
   * @return void
   */
  public function testHashIncludesFrameworkVersion(): void
  {
    $plainFileHash = hash_file('md5', $this->tempFile);
    $cacheHash = RouterTool::getCacheHash($this->tempFile);
    static::assertNotSame($plainFileHash, $cacheHash);
    // 版本号 + 文件哈希的完整摘要应可复现
    static::assertSame(
      md5(App::VERSION . ':' . $plainFileHash),
      $cacheHash
    );
  }

  /**
   * 测试控制器文件变更后哈希随之变化
   *
   * @return void
   */
  public function testHashChangesWhenFileChanges(): void
  {
    $before = RouterTool::getCacheHash($this->tempFile);
    file_put_contents($this->tempFile, "<?php\nclass A { public int \$v = 1; }\n");
    clearstatcache(true, $this->tempFile);
    $after = RouterTool::getCacheHash($this->tempFile);
    static::assertNotSame($before, $after);
  }
}
