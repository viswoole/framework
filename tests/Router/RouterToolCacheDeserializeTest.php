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
use Viswoole\Router\Route\Group;
use Viswoole\Router\Route\Route;
use Viswoole\Router\RouterTool;

/**
 * 模拟攻击者的 gadget 类：反序列化时通过 __wakeup 标记实例化行为
 *
 * 该类不在路由缓存反序列化白名单内，getCache() 处理被篡改的缓存文件时
 * 不得实例化它（否则构成对象注入攻击面）
 */
class DeserializeGadget
{
  /** @var bool 是否被实例化（__wakeup 是否触发） */
  public static bool $woken = false;

  /**
   * 反序列化钩子：被实例化时置位标记
   */
  public function __wakeup(): void
  {
    self::$woken = true;
  }
}

/**
 * 路由缓存反序列化安全测试
 *
 * 验证 getCache 对缓存文件的 unserialize 施加类白名单：
 * 缓存文件（runtime/route/*.cache）可被同权限进程篡改，
 * 无白名单的任意类恢复会构成 PHP 对象注入（CWE-502）
 */
class RouterToolCacheDeserializeTest extends TestCase
{
  /** @var string 测试用临时控制器文件（仅用于计算合法哈希） */
  private string $tempFile;

  /** @var string 测试用缓存文件绝对路径 */
  private string $cacheFile;

  /** @var string 测试用服务名 */
  private string $server = 'cache-deserialize-test';

  /** @var string 测试用控制器类名 */
  private string $controller = 'Fake\\CacheDeserController';

  /**
   * 初始化测试环境并计算合法缓存哈希
   */
  protected function setUp(): void
  {
    App::factory();
    DeserializeGadget::$woken = false;
    $this->tempFile = sys_get_temp_dir() . '/router_cache_deser_' . uniqid() . '.php';
    file_put_contents($this->tempFile, "<?php\nclass A {}\n");
    $dir = RouterTool::getCachePath($this->server);
    $this->cacheFile = $dir . DIRECTORY_SEPARATOR
      . str_replace('\\', '_', $this->controller) . '.cache';
  }

  /**
   * 清理测试产生的缓存文件与临时目录
   */
  protected function tearDown(): void
  {
    @unlink($this->cacheFile);
    @rmdir(dirname($this->cacheFile));
    @unlink($this->tempFile);
  }

  /**
   * 测试被篡改的缓存文件不得实例化白名单外的类
   *
   * 构造携带合法哈希但 route 字段为 gadget 对象的缓存文件：
   * 若 unserialize 未限制 allowed_classes，gadget 会被实例化并触发 __wakeup
   *
   * @return void
   */
  public function testTamperedCacheDoesNotInstantiateForeignClass(): void
  {
    $hash = RouterTool::getCacheHash($this->tempFile);
    $payload = serialize([
      'hash'  => $hash,
      'route' => new DeserializeGadget(),
    ]);
    file_put_contents($this->cacheFile, $payload);

    $result = RouterTool::getCache($this->server, $this->controller, $hash);

    static::assertFalse(
      DeserializeGadget::$woken,
      '白名单外的类在缓存反序列化时被实例化，存在对象注入风险'
    );
    static::assertNull($result, '非 Group 结构的缓存内容应判定为无效缓存');
  }

  /**
   * 测试合法路由缓存的序列化/反序列化往返不受白名单影响
   *
   * @return void
   */
  public function testLegitCacheRoundTrip(): void
  {
    $hash = RouterTool::getCacheHash($this->tempFile);
    $group = new Group('/cache-roundtrip', [], null, id: 'cache-roundtrip');
    $route = new Route('/child', [RouterTool::class, 'generateHashId'], methods: ['GET']);
    $group->addItem($route);
    RouterTool::setCache($this->server, $this->controller, $hash, $group);

    $restored = RouterTool::getCache($this->server, $this->controller, $hash);

    static::assertInstanceOf(Group::class, $restored);
    $children = $restored->getItem();
    static::assertCount(1, $children);
    $child = reset($children);
    static::assertInstanceOf(Route::class, $child);
    // addItem 不触发父子路径合并，子路由保留自身注册路径
    static::assertSame(['/child'], $child->getPaths());
  }
}
