/** 职责：通过真实 Swoole 服务验证停止窗口、完成通知和超时强停，不连接业务服务。 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { beforeAll, describe, expect, it } from 'vitest';

interface StopObservations {
  elapsed: number;
  completed: boolean;
  restarted: boolean;
  status: string | null;
  activeTasks: number | null;
  messages: { message: string; level: string }[];
  hasAliveProcesses: boolean;
  hasPidFile: boolean;
  nativeMessages: string;
  logs: string;
}
interface ShutdownObservations {
  frameworkConfig: { mergedWait: number; nativeWait: number; reloadAsync: boolean; pidMatches: boolean; hasWorkerExitHook: boolean };
  counters: { taskDuring: number; taskAfter: number; taskResult: { ok: boolean }; requestDuring: number; requestAfter: number; requestThrew: boolean };
  policy: { defaults: { reload_async: boolean; max_wait_time: number }; options: { reload_async: boolean; max_wait_time: number }; waitSeconds: number; rejected: number };
  graceful: StopObservations;
  restart: StopObservations;
  swooleTimeout: StopObservations;
  frameworkTimeout: StopObservations;
  legacy: StopObservations;
  unconfirmedMessage: string;
}
let observations: ShutdownObservations;
beforeAll((): void => {
  const configured: unknown = JSON.parse(process.env.QUEUE_TEST_PHP_COMMAND ?? '["php"]');
  if (!Array.isArray(configured) || !configured.every((value: unknown): boolean => typeof value === 'string')) {
    throw new Error('QUEUE_TEST_PHP_COMMAND must be a JSON string array');
  }
  const command = configured as string[];
  const executable = command[0];
  if (!executable) throw new Error('PHP runner command cannot be empty');
  const fixture = process.env.QUEUE_TEST_PHP_FIXTURE?.replace(/scenarios\.php$/, 'shutdown-scenarios.php')
    ?? fileURLToPath(new URL('./fixtures/shutdown-scenarios.php', import.meta.url));
  observations = JSON.parse(execFileSync(executable, [...command.slice(1), fixture], {
    encoding: 'utf8', timeout: 45_000,
  })) as ShutdownObservations;
  const configFixture = fixture.replace('shutdown-scenarios.php', 'shutdown-config.php');
  observations.frameworkConfig = JSON.parse(execFileSync(executable, [...command.slice(1), configFixture], {
    encoding: 'utf8', timeout: 5000,
  })) as ShutdownObservations['frameworkConfig'];
}, 50_000);

describe('服务优雅停止', (): void => {
  it('真实 Server 把服务级窗口传入 Swoole，并与停止命令使用相同 PID 路径', (): void => {
    expect(observations.frameworkConfig).toEqual({ mergedWait: 12, nativeWait: 12,
      reloadAsync: true, pidMatches: true, hasWorkerExitHook: true });
  });
  it('任务和请求计数在异常退出时归零并保留原始返回值', (): void => {
    expect(observations.counters).toEqual({ taskDuring: 1, taskAfter: 0, taskResult: { ok: true },
      requestDuring: 1, requestAfter: 0, requestThrew: true });
  });
  it('开启协程优雅退出并统一全局、服务覆盖与命令等待预算', (): void => {
    expect(observations.policy.defaults).toEqual({ reload_async: true, max_wait_time: 60 });
    expect(observations.policy.options).toEqual({ reload_async: true, max_wait_time: 12 });
    expect(observations.policy.waitSeconds).toBe(29);
    expect(observations.policy.rejected).toBe(4);
  });
  it('运行超过旧 10 秒上限的任务完成后才输出正常停止，并采用实际启动配置', (): void => {
    expect(observations.graceful.completed).toBe(true);
    expect(observations.graceful.status).toBe('graceful');
    expect(observations.graceful.activeTasks).toBe(0);
    expect(observations.graceful.elapsed).toBeGreaterThan(10);
    expect(observations.graceful.messages.at(-1)?.level).toBe('SUCCESS');
    expect(observations.graceful.messages.at(-1)?.message).toContain('正常停止');
    expect(observations.graceful.hasAliveProcesses).toBe(false);
    expect(observations.graceful.hasPidFile).toBe(false);
  });
  it('强制重启也等待超过旧 5 秒上限的任务，正常结束后才启动新服务', (): void => {
    expect(observations.restart.completed).toBe(true);
    expect(observations.restart.status).toBe('graceful');
    expect(observations.restart.elapsed).toBeGreaterThan(5);
    expect(observations.restart.restarted).toBe(true);
    expect(observations.restart.hasAliveProcesses).toBe(false);
    expect(observations.restart.messages.at(-1)?.level).toBe('SUCCESS');
  });
  it('Swoole 内部超时中断任务时报告强停，不能伪报任务全部结束', (): void => {
    expect(observations.swooleTimeout.completed).toBe(false);
    expect(observations.swooleTimeout.status).toBe('forced');
    expect(observations.swooleTimeout.activeTasks).toBeGreaterThan(0);
    expect(observations.swooleTimeout.messages.at(-1)?.level).toBe('WARNING');
    expect(observations.swooleTimeout.nativeMessages).not.toContain('正常停止');
    expect(observations.swooleTimeout.hasAliveProcesses).toBe(false);
  });
  it('框架预算耗尽后强停整个进程树，输出警告且仅在全部退出后清理 PID', (): void => {
    expect(observations.frameworkTimeout.elapsed).toBeGreaterThanOrEqual(7);
    expect(observations.frameworkTimeout.messages.at(-1)?.level).toBe('WARNING');
    expect(observations.frameworkTimeout.messages.at(-1)?.message).toContain('强停');
    expect(observations.frameworkTimeout.hasAliveProcesses).toBe(false);
    expect(observations.frameworkTimeout.hasPidFile).toBe(false);
  });
  it('旧实例缺少回执时明确表达无法确认任务状态', (): void => {
    expect(observations.unconfirmedMessage).toContain('缺少停机回执');
    expect(observations.unconfirmedMessage).not.toContain('正常停止');
    expect(observations.legacy.hasAliveProcesses).toBe(false);
    expect(observations.legacy.hasPidFile).toBe(false);
    expect(observations.legacy.messages.at(-1)?.level).toBe('WARNING');
    expect(observations.legacy.messages.at(-1)?.message).toContain('缺少停机回执');
  });
});
