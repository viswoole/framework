<?php
/**
 * 职责：在独立 PHP 进程执行集成场景，收集 JSON、错误输出并限制执行时间。
 */
declare(strict_types=1);

namespace Viswoole\Tests\TaskQueue\Support;

use JsonException;
use RuntimeException;

require_once __DIR__ . "/ScenarioProcess.php";

/**
 * 隔离场景中的全局桩、fork 和 Swoole reactor，不负责业务断言。
 */
final class ScenarioRunner
{
    /**
     * 使用当前 PHP 解释器运行场景，不引入 Node 或额外的运行器协议。
     * @param string $name 场景文件名
     * @param int $timeout 最大运行秒数
     * @return array<string, mixed> JSON 观测值
     * @throws RuntimeException 场景执行失败、超时或结果无效
     */
    public static function run(string $name, int $timeout): array
    {
        $output = tmpfile();
        $errors = tmpfile();
        if ($output === false || $errors === false) {
            if (is_resource($output)) fclose($output);
            if (is_resource($errors)) fclose($errors);
            throw new RuntimeException('无法创建场景输出临时文件');
        }
        $process = null;
        try {
            $process = new ScenarioProcess(dirname(__DIR__) . '/fixtures/' . $name,
                [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $errors]);
            $exitCode = $process->awaitExit($timeout, $name);
            rewind($output);
            rewind($errors);
            $json = stream_get_contents($output);
            $stderr = stream_get_contents($errors);
            if ($exitCode !== 0) {
                throw new RuntimeException("PHP 场景 {$name} 退出码 {$exitCode}：{$stderr}\n{$json}");
            }
            return self::decode($json, $name);
        } finally {
            try {
                if ($process !== null) $process->close();
            } finally {
                fclose($output);
                fclose($errors);
            }
        }
    }

    /**
     * 拒绝非 JSON 对象结果，避免输出污染被误认为测试通过。
     * @param string $json 场景标准输出
     * @param string $name 场景名
     * @return array<string, mixed> 观测值
     * @throws RuntimeException 场景输出格式错误
     */
    private static function decode(string $json, string $name): array
    {
        try {
            $observations = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("PHP 场景 {$name} 未输出有效 JSON：{$json}", 0, $exception);
        }
        if (!is_array($observations) || !str_starts_with(ltrim($json), '{')) {
            throw new RuntimeException("PHP 场景 {$name} 必须输出 JSON 对象");
        }
        return $observations;
    }
}
