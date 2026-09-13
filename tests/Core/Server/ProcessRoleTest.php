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

namespace Viswoole\Tests\Core\Server;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Viswoole\Core\Server\ProcessRole;

/**
 * 进程角色标记测试
 *
 * 验证角色流转（CLI → MASTER → WORKER）与判定方法的语义正确性。
 * 角色为进程级静态状态，每个用例前后通过反射重置，避免污染其他测试。
 */
class ProcessRoleTest extends TestCase
{
  /**
   * 通过反射重置静态角色为 CLI（默认值）
   */
  private static function resetRole(): void
  {
    // PHP 8.4 中私有静态属性反射读写无需 setAccessible（已于 8.1 起默认可访问）
    $role = new ReflectionProperty(ProcessRole::class, 'role');
    $role->setValue(null, ProcessRole::CLI);
  }

  /**
   * @return void
   */
  protected function setUp(): void
  {
    self::resetRole();
  }

  /**
   * @return void
   */
  protected function tearDown(): void
  {
    self::resetRole();
  }

  /**
   * 默认角色为 CLI：非 server 模式、非 worker
   *
   * @return void
   */
  public function testDefaultRoleIsCli(): void
  {
    static::assertFalse(ProcessRole::isServerMode(), '未启动 server 前应处于 CLI 角色');
    static::assertFalse(ProcessRole::isWorker());
  }

  /**
   * 标记 master 后：处于 server 模式但不是 worker
   *
   * @return void
   */
  public function testMarkAsMaster(): void
  {
    ProcessRole::markAsMaster();
    static::assertTrue(ProcessRole::isServerMode(), 'master 角色属于 server 运行模式');
    static::assertFalse(ProcessRole::isWorker(), 'master 不是 worker');
  }

  /**
   * 标记 worker 后：处于 server 模式且是 worker
   *
   * @return void
   */
  public function testMarkAsWorker(): void
  {
    ProcessRole::markAsWorker();
    static::assertTrue(ProcessRole::isServerMode());
    static::assertTrue(ProcessRole::isWorker(), 'workerStart 后应标记为 worker 角色');
  }
}
