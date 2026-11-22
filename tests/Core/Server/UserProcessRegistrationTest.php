<?php

declare(strict_types=1);

/**
 * 职责：验证用户进程注册与停机回执登记之间的约束，不启动真实服务。
 */

namespace Viswoole\Tests\Core\Server;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Process;
use Swoole\Server;
use Viswoole\Core\Server\Shutdown\ShutdownRuntime;

/**
 * 验证原生注册结果如何影响服务的停机回执。
 */
final class UserProcessRegistrationTest extends TestCase
{
    /**
     * 注册失败不留下回执，原生编号为零时仍视为注册成功。
     *
     * @return void 验证回执只登记在注册成功的服务下
     */
    public function testOnlySuccessfulRegistrationTracksAReceipt(): void
    {
        ShutdownRuntime::prepare();
        $server = $this->createMock(Server::class);
        $server->expects(self::exactly(2))
            ->method('addProcess')
            ->with(self::isInstanceOf(Process::class))
            ->willReturnOnConsecutiveCalls(false, 0);

        $registry = (new ReflectionProperty(ShutdownRuntime::class, 'userProcesses'))->getValue();
        $entry = static function (): void {
            self::fail('注册时不应执行用户进程入口');
        };

        self::assertFalse(ShutdownRuntime::registerUserProcess($server, $entry));
        self::assertFalse(isset($registry[$server]));
        self::assertSame(0, ShutdownRuntime::registerUserProcess($server, $entry));
        self::assertCount(1, $registry[$server]);
    }
}
