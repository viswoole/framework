/**
 * 职责：用 Vitest 断言真实 PHP/Redis 场景；运行器可切换本机 PHP 或隔离容器。
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { beforeAll, describe, expect, it } from 'vitest';

interface QueueObservations {
  groups: { first: string[]; second: string[]; firstId: string; secondId: string };
  initialLease: false;
  dispatches: number;
  retainedBeforeConsume: boolean;
  handlers: number;
  firstGroupAfterConsume: string[];
  otherGroupAfterConsume: string[];
  lateDispatch: false;
  failedDispatch: false;
  retainedAfterFailure: boolean;
  retryDispatch: number;
  legacy: { groups: string[]; members: string[]; foreignExists: boolean };
  staleMembers: string[];
  registeredGroup: boolean;
  entry: { first: { ok: boolean }; second: false; calls: number };
  nonQueue: { ok: boolean };
  nonQueueCalls: number;
  discard: { exists: boolean; members: string[] };
  cleanupRecovery: { result: number; calls: number; staleMember: boolean; logged: boolean; redeliveries: number; removed: boolean };
  unknownTopic: { threw: boolean; dataExists: boolean; members: string[]; redeliveries: number };
  capabilities: { rejected: number; nonQueue: number; hasFileWrites: boolean; fileSupported: boolean; redisSupported: boolean; wrappedConsume: boolean };
  groupPruning: { emptyRemoved: boolean; pendingRegistered: boolean; unrelatedRegistered: boolean };
  failingCleanupLogger: { consumed: boolean; duplicate: boolean; hasMessage: boolean; hasQueueId: boolean; hasOriginalError: boolean; hasLoggerError: boolean };
  pruningRace: { survivors: number; missing: number; registered: boolean; removedAfterConsume: boolean };
}

interface LifecycleObservations {
  startupReturned: boolean;
  recoveryScheduled: boolean;
  invalidTimerContained: boolean;
  timerCalls: number;
  minimumDelay: number;
  loggedStoreError: boolean;
  loggedScanError: boolean;
  loggedBackendError: boolean;
  loggedTimerError: boolean;
}

let observations: QueueObservations;
let lifecycle: LifecycleObservations;

beforeAll((): void => {
  const configured: unknown = JSON.parse(process.env.QUEUE_TEST_PHP_COMMAND ?? '["php"]');
  if (!Array.isArray(configured) || !configured.every((value: unknown): boolean => typeof value === 'string')) {
    throw new Error('QUEUE_TEST_PHP_COMMAND must be a JSON string array');
  }
  const command = configured as string[];
  const executable = command[0];
  if (!executable) throw new Error('PHP runner command cannot be empty');
  const fixture = process.env.QUEUE_TEST_PHP_FIXTURE ?? fileURLToPath(new URL('./fixtures/scenarios.php', import.meta.url));
  const output = execFileSync(executable, [...command.slice(1), fixture], { encoding: 'utf8', timeout: 25_000 });
  observations = JSON.parse(output) as QueueObservations;
  const lifecycleFixture = fixture.replace(/scenarios\.php$/, 'lifecycle.php');
  const lifecycleOutput = execFileSync(executable, [...command.slice(1), lifecycleFixture], { encoding: 'utf8', timeout: 10_000 });
  const timerFixture = fixture.replace(/scenarios\.php$/, 'timers.php');
  const timerOutput = execFileSync(executable, [...command.slice(1), timerFixture], { encoding: 'utf8', timeout: 10_000 });
  lifecycle = { ...JSON.parse(lifecycleOutput), ...JSON.parse(timerOutput) } as LifecycleObservations;
}, 30_000);

describe('跨实例 Redis 任务恢复', (): void => {
  it('隔离相同 Worker ID 的实例分组与任务 ID', (): void => {
    expect(observations.groups.first).toEqual([observations.groups.firstId]);
    expect(observations.groups.second).toEqual([observations.groups.secondId]);
    expect(observations.groups.firstId).not.toBe(observations.groups.secondId);
  });

  it('首次投递租约阻止恢复扫描抢先投递', (): void => {
    expect(observations.initialLease).toBe(false);
  });

  it('认领者崩溃、租约到期后八个进程只有一个投递成功', (): void => {
    expect(observations.dispatches).toBe(1);
    expect(observations.retainedBeforeConsume).toBe(true);
  });

  it('重复投递只有一个消费者执行并清理原实例分组', (): void => {
    expect(observations.handlers).toBe(1);
    expect(observations.firstGroupAfterConsume).toEqual([]);
    expect(observations.otherGroupAfterConsume).toEqual([observations.groups.secondId]);
    expect(observations.lateDispatch).toBe(false);
  });

  it('恢复投递失败保留数据并允许租约到期后重试，任务 ID 0 视为成功', (): void => {
    expect(observations.failedDispatch).toBe(false);
    expect(observations.retainedAfterFailure).toBe(true);
    expect(observations.retryDispatch).toBe(0);
  });

  it('兼容任意旧 Worker 分组并跳过无关缓存标签', (): void => {
    expect(observations.legacy.groups).toEqual(['5']);
    expect(observations.legacy.members).toEqual([]);
    expect(observations.legacy.foreignExists).toBe(true);
  });

  it('清理崩溃遗留成员并回收空分组注册', (): void => {
    expect(observations.staleMembers).toEqual([]);
    expect(observations.registeredGroup).toBe(false);
  });

  it('真实 onTask 入口只执行一次并保留处理器返回值', (): void => {
    expect(observations.entry).toEqual({ first: { ok: true }, second: false, calls: 1 });
    expect(observations.nonQueue).toEqual({ ok: true });
    expect(observations.nonQueueCalls).toBe(2);
  });

  it('首次明确投递失败时清理数据和对应分组', (): void => {
    expect(observations.discard).toEqual({ exists: false, members: [] });
  });

  it('消费后的索引故障不阻断处理器，记录错误并由扫描补清理', (): void => {
    expect(observations.cleanupRecovery).toEqual({
      result: 1, calls: 1, staleMember: true, logged: true, redeliveries: 0, removed: true,
    });
  });

  it('未注册主题的持久化任务被消费清理，主题校验抛异常且恢复扫描不再重投', (): void => {
    expect(observations.unknownTopic).toEqual({
      threw: true, dataExists: false, members: [], redeliveries: 0,
    });
  });

  it('File 及其包装器在写入前拒绝持久化队列，非队列任务仍正常执行', (): void => {
    expect(observations.capabilities).toEqual({
      rejected: 2, nonQueue: 42, hasFileWrites: false,
      fileSupported: false, redisSupported: true, wrappedConsume: true,
    });
  });

  it('回收历史空组并保留非空组与无关标签', (): void => {
    expect(observations.groupPruning).toEqual({
      emptyRemoved: true, pendingRegistered: true, unrelatedRegistered: true,
    });
  });

  it('八个进程交错新增和回收时保留全部待消费成员，消费完后回收分组', (): void => {
    expect(observations.pruningRace).toEqual({
      survivors: 8, missing: 0, registered: true, removedAfterConsume: true,
    });
  });

  it('日志回调抛异常时保留完整兜底消息并继续消费', (): void => {
    expect(observations.failingCleanupLogger).toEqual({
      consumed: true, duplicate: false, hasMessage: true, hasQueueId: true,
      hasOriginalError: true, hasLoggerError: true,
    });
  });

  it('生产启动及恢复路径在商店、扫描和日志故障下仍能返回并注册恢复定时器', (): void => {
    expect(lifecycle.startupReturned).toBe(true);
    expect(lifecycle.recoveryScheduled).toBe(true);
    expect(lifecycle.loggedStoreError).toBe(true);
    expect(lifecycle.loggedScanError).toBe(true);
    expect(lifecycle.loggedBackendError).toBe(true);
  });

  it('随机延时下界为 1ms 且实际触发扫描，定时器创建错误被记录并隔离', (): void => {
    expect(lifecycle.minimumDelay).toBe(1);
    expect(lifecycle.timerCalls).toBe(1);
    expect(lifecycle.invalidTimerContained).toBe(true);
    expect(lifecycle.loggedTimerError).toBe(true);
  });
});
