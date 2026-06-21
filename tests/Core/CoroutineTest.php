<?php

declare(strict_types=1);

namespace Viswoole\Tests\Core;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Context;
use Viswoole\Core\Coroutine;

/**
 * 协程管理测试
 *
 * 在非协程环境下测试 Coroutine 类的兼容性方法
 */
class CoroutineTest extends TestCase
{
  /**
   * 测试非协程环境下 isCoroutine 返回 false
   *
   * @return void
   */
  public function testIsCoroutineInNonCoroutineEnv(): void
  {
    // 在 PHPUnit 普通测试中，不在 Swoole 协程内
    static::assertFalse(Coroutine::isCoroutine());
  }

  /**
   * 测试非协程环境下 getContext 返回模拟上下文对象
   *
   * @return void
   */
  public function testGetContextInNonCoroutineEnv(): void
  {
    $context = Coroutine::getContext();
    // 非协程环境应返回 Context 实例（模拟上下文）
    static::assertInstanceOf(Context::class, $context);
  }

  /**
   * 测试非协程环境下多次调用 getContext 返回同一实例
   *
   * @return void
   */
  public function testGetContextReturnsSameInstance(): void
  {
    $context1 = Coroutine::getContext();
    $context2 = Coroutine::getContext();
    // 非协程环境下应返回同一个模拟上下文实例（单例模式）
    static::assertSame($context1, $context2);
  }

  /**
   * 测试非协程环境下 getTopId 返回 false
   *
   * @return void
   */
  public function testGetTopIdInNonCoroutineEnv(): void
  {
    // 非协程环境下 getCid() 返回 -1，getTopId 应返回 false
    static::assertFalse(Coroutine::getTopId());
  }

  /**
   * 测试非协程环境下 id() 返回 -1
   *
   * @return void
   */
  public function testIdInNonCoroutineEnv(): void
  {
    // id() 内部调用 getCid()，非协程环境应返回 -1
    static::assertEquals(-1, Coroutine::id());
  }
}
