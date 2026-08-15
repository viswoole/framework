<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Viswoole\Core\App;

use function Co\run;

/**
 * 容器协程上下文单例测试
 *
 * 验证协程环境下单例读写位置对称（均为请求根协程 getTopId）：
 * 子协程内 make/hasInstance 应命中根协程上已有的单例，
 * 而非 miss 后重复实例化并覆盖根协程旧单例。
 *
 * 注：结果经引用变量传回主流程断言——Co\run 同步阻塞至协程结束，
 * Channel::pop() 不可在非协程主流程调用（Swoole\Error）。
 */
class ContainerCoroutineTest extends TestCase
{
  private App $app;

  /**
   * 初始化容器实例并开启输出缓冲
   */
  protected function setUp(): void
  {
    $this->app = App::factory();
    ob_start();
  }

  /**
   * 清理输出缓冲
   */
  protected function tearDown(): void
  {
    ob_end_clean();
  }

  /**
   * 绑定计数器测试桩（make 默认缓存单例；计数器用于识别实例是否被重建）
   */
  private function bindCounterSingleton(): void
  {
    $this->app->bind('test.co_counter', function () {
      static $built = 0;
      $obj = new \stdClass();
      $obj->builtOrder = ++$built;
      return $obj;
    });
  }

  /**
   * 子协程内 make 应命中根协程单例（同一实例，不重复实例化）
   */
  public function testChildCoroutineMakeReusesRootSingleton(): void
  {
    $this->bindCounterSingleton();
    $root = $this->app->make('test.co_counter');

    $result = null;
    run(function () use (&$result, $root) {
      $child = $this->app->make('test.co_counter');
      $result = ['same' => $child === $root, 'order' => $child->builtOrder];
    });
    self::assertTrue($result['same'], '子协程应命中根协程单例，而非重复实例化');
    self::assertSame(1, $result['order'], '不应触发第二次实例化');
  }

  /**
   * 子协程内 hasInstance 应命中根协程单例
   */
  public function testChildCoroutineHasInstanceSeesRootSingleton(): void
  {
    $this->bindCounterSingleton();
    $this->app->make('test.co_counter');

    $result = false;
    run(function () use (&$result) {
      $result = $this->app->hasInstance('test.co_counter');
    });
    self::assertTrue($result);
  }

  /**
   * 嵌套孙协程同样命中根协程单例
   */
  public function testGrandchildCoroutineMakeReusesRootSingleton(): void
  {
    $this->bindCounterSingleton();
    $root = $this->app->make('test.co_counter');

    $result = false;
    run(function () use (&$result, $root) {
      Coroutine::create(function () use (&$result, $root) {
        $result = $this->app->make('test.co_counter') === $root;
      });
    });
    self::assertTrue($result);
  }
}
