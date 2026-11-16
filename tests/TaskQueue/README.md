# Redis 队列恢复测试

需要 PHP >= 8.4、Swoole、phpredis 以及独立的测试 Redis（默认 127.0.0.1:6379）。测试使用随机键前缀，只清理自己创建的键，不执行 FLUSHDB。

```sh
pnpm --dir tests/TaskQueue install
pnpm --dir tests/TaskQueue test
```

远程测试 Redis 可设置 `QUEUE_TEST_REDIS_HOST`。没有本机 PHP 时，`QUEUE_TEST_PHP_COMMAND` 可设置为 JSON 字符串数组，例如 `["docker","run","--rm",...]`；同时将仓库只读挂载到容器，并用 `QUEUE_TEST_PHP_FIXTURE` 指定容器内 `scenarios.php` 的绝对路径。

测试使用八个独立 PHP 进程竞争真实 Redis。租约到期由 Redis PEXPIRE 推进，覆盖恢复认领、消费去重、遗留分组及失败重试。

持久化任务队列需要 `AtomicCacheDriverInterface` 契约中的原子 NX、pull 和集合回收能力。内置 File 驱动及其监控包装器会在写入前被拒绝；请将 `task.store` 指向 Redis 商店。升级前如有 File 队列残留，需要先用旧版本排空，不能直接切换存储。非持久化任务继续支持原有配置。

索引清理故障不会阻止已经取得消费权的任务执行，错误默认通过 PHP `error_log` 留痕，生产环境由 TaskManager 注入框架任务日志通道（Log::task），日志通道自身故障时降级回 `error_log`；残留成员由周期扫描重试。Redis Lua 原子回收空分组及注册项，测试覆盖八个进程交错写入与回收，防止历史分组累积或新任务失去恢复入口。投递租约与恢复扫描周期默认 30 秒，可通过 `task.lease_seconds` 配置调整（不小于 1 秒）。

生命周期回归使用真实 TaskManager 注册路径，注入商店、扫描与日志后端的双重故障；定时器在独立 PHP 进程的 CLI reactor 中验证，固定随机延时下界为 1ms，并验证周期回调真实触发。日志降级测试将 PHP 警告转为异常，检查完整任务标识、原始异常及日志后端异常均保留。

## 服务停止回归

`shutdown.test.ts` 使用临时目录和无端口映射的独立 Swoole 服务，验证 11 秒任务完成后才正常退出、Swoole 超时中断提示和框架超时强杀进程树。该文件不需要 Redis，可单独运行 `pnpm --dir tests/TaskQueue exec vitest run shutdown.test.ts`；容器运行方式与上文相同。

服务默认启用 `reload_async=true`、`max_wait_time=60` 秒，可由 `server.options` 或服务级 `options` 调整窗口。停止和 `server:start --force` 都等待实际启动窗口的两倍再加 5 秒，然后才执行框架强停。实际启动配置和退出结果保存在 PID 文件旁的 `.shutdown.json`，不依赖部署后已修改的配置。正常退出仅表示已经进入 Task 和请求回调的工作均已结束，不表示业务执行成功；Swoole 中断仍在处理的任务、框架强杀以及旧实例缺少回执时都输出警告。

Worker 退出时仅清理框架队列恢复定时器，停止期间拒绝新任务投递和恢复重投。已有 Redis 队列仍保持消费即移除语义，被超时中断的处理器不会自动重试。在 Linux 上，强停会核对 `/proc` 启动标识并清理捕获的子孙进程；无 `/proc` 平台只跟踪主进程，不能提供相同的进程树清理保证。

### Swoole 稳定版隔离升级验证

已复现 Swoole 6.0.2 的上游问题：Server 配置 `max_wait_time=15`，但 Task Worker 进程池仍使用独立的 3 秒默认值，11 秒任务会提前中断。位置为上游 `src/server/process.cc::Factory::kill_task_workers()` 调用 `ProcessPool::shutdown()`，后者采用进程池自己的 `max_wait_time`。框架不能通过延长 CLI 等待修复该扩展行为；测试不会跳过或隐藏这个失败。

官方稳定版 6.2.5 已改变这条停机路径。以下验证镜像只替换独立镜像中的 Swoole，不更新业务容器、不改框架的 Composer 版本约束：

```sh
docker build -t viswoole-shutdown-test:swoole-6.2.5 tests/TaskQueue/docker
docker run --rm --network none -v "$PWD:/workspace:ro" --entrypoint php viswoole-shutdown-test:swoole-6.2.5 /workspace/tests/TaskQueue/fixtures/shutdown-scenarios.php
```

运行 Vitest 时将 `QUEUE_TEST_PHP_COMMAND` 中的镜像替换为 `viswoole-shutdown-test:swoole-6.2.5`，并设置 `QUEUE_TEST_PHP_FIXTURE=/workspace/tests/TaskQueue/fixtures/scenarios.php`。该镜像仅用于停机行为验证；生产升级前仍需对照现有镜像保留 Swoole 的编译功能，并在预发布环境验证业务。没有执行业务镜像替换或发布。

本次验证结果：PHP 8.4.27 / Swoole 6.2.5 的 25 项 Vitest（17 项队列回归、8 项停机回归）全部通过，现有 6 项 PID/进程角色 PHPUnit 回归通过；PHP 语法、TypeScript 严格类型及差异检查通过。Swoole 6.0.2 的提前中断已保留为版本边界证据，不能宣称仅调整框架配置就能消除这个上游限制。
