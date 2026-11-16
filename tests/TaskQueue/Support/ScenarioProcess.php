<?php
/**
 * 职责：管理隔离场景的进程组与超时清理；不解析场景输出。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue\Support;

use RuntimeException;
use Viswoole\Core\Server\Shutdown\ServerProcessTree;

/**
 * 启动握手确认独立进程组后，才允许按负 PGID 清理全部后代。
 */
final class ScenarioProcess
{
    /**
     * @var resource 场景入口进程句柄
     */
    private mixed $process;
    /**
     * @var int 入口进程与会话进程组的共同标识
     */
    private int $groupId;
    /**
     * @var ServerProcessTree 已确认归属的进程树，用于检查清理结果
     */
    private ServerProcessTree $tree;

    /**
     * 启动独立会话并等待握手，避免超时清理误杀测试运行器所在进程组。
     * @param string $path 场景绝对路径
     * @param array $streams 标准输入输出描述符
     * @throws RuntimeException 启动或握手失败
     */
    public function __construct(string $path, array $streams)
    {
        $streams[3] = ['pipe', 'w'];
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/launch-scenario.php', $path], $streams, $pipes);
        if (!is_resource($this->process)) throw new RuntimeException('无法启动场景：' . $path);
        $status = proc_get_status($this->process);
        stream_set_timeout($pipes[3], 5);
        $ready = fgets($pipes[3]);
        fclose($pipes[3]);
        $this->groupId = $status['pid'];
        if (trim($ready ?: '') !== (string)$this->groupId) {
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
            throw new RuntimeException('场景独立进程组握手失败：' . $path);
        }
        $this->tree = new ServerProcessTree($this->groupId);
    }

    /**
     * 等待入口退出，预算耗尽时由调用方 finally 清理整个会话。
     * @param int $timeout 执行预算秒数
     * @param string $name 场景名称
     * @return int 入口退出码
     * @throws RuntimeException 场景超时
     */
    public function awaitExit(int $timeout, string $name): int
    {
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($this->process);
            if (!$status['running']) return $status['exitcode'];
            if (microtime(true) >= $deadline) throw new RuntimeException("PHP 场景 {$name} 超过 {$timeout} 秒执行预算");
            usleep(10000);
        } while (true);
    }

    /**
     * 终止组内所有进程并检查已跟踪进程树，忽略不再运行代码的僵尸。
     * @return void
     * @throws RuntimeException 清理后仍有场景进程存活
     */
    public function close(): void
    {
        $this->tree->isAlive();
        if (posix_kill(-$this->groupId, 0) && !posix_kill(-$this->groupId, SIGKILL)) {
            throw new RuntimeException('无法终止场景进程组：' . $this->groupId);
        }
        proc_close($this->process);
        $deadline = microtime(true) + 2;
        while ($this->tree->isAlive()) {
            if (microtime(true) >= $deadline) throw new RuntimeException('场景进程树未全部退出：' . $this->groupId);
            usleep(10000);
        }
    }
}
