# Viswoole 核心依赖库

___

> 此仓库是[`Viswoole`](https://viswoole.com)框架的核心依赖存储库，需配合`Viswoole`框架使用。

## 文档

[Viswoole开发文档](https://viswoole.com)

### 安装

```bash
composer create-project viswoole/viswoole myProject
```

### 更新

```bash
composer update viswoole/framework
```

### 自定义用户进程的停机确认

在服务初始化后、启动前，调用 `ShutdownRuntime::registerUserProcess($server, $handler)` 注册用户进程，
其中 `$server` 为原生 Swoole 服务（可通过框架服务的 `getServer()` 取得）。该方法返回原生进程编号，
注册失败时返回 `false`。框架在每次子进程启动时重置确认状态，再向入口闭包传入
`Swoole\Process` 和 `UserProcessShutdown`。进程在停止接收新工作、排空在途工作并完成清理后，
调用回执对象的 `acknowledge()`，随后退出进程。

可通过第三个参数 `UserProcessOptions` 保留原有进程配置。例如，
`new UserProcessOptions(pipeType: 0, enableCoroutine: true)` 使用默认关闭的标准输入输出重定向，
禁用管道并启用协程。启用标准输入输出重定向时，Swoole 会创建管道，即使 `pipeType` 为 `0`。
省略整个配置参数时沿用 Swoole 默认值。

停机回执中的 `expected_user_processes`、`tracked_user_processes` 和
`pending_user_processes` 分别记录 Swoole 的用户进程数、登记的确认数、尚未确认排空的数量。
已经登记但未确认排空的进程会使回执为 `forced`。存在未登记的用户进程且没有其他强停证据时，回执为 `unconfirmed`。

### 事务提交后回调

`Db::afterCommit(Closure $callback): void` 在当前事务的最外层确认提交后执行无参数回调。`DbManager` 和 `ConnectManager` 同样提供该方法。

```php
Db::startTransaction(function (): void {
  // 在同库事务内保存业务数据及持久化任务意图。
  Db::afterCommit(static function (): void {
    // 发送仅用于加速的唤醒通知。
  });
});
```

- 内层提交只释放保存点，回调按顺序合并至父层；父层回滚也会删除已合并回调。
- 内层回滚只丢弃该层回调，同层重新开启事务不会恢复旧回调。
- 最外层确认提交并清理连接、重置事务上下文后逐条执行，回调可安全开启新事务。
- 回调异常通过数据库日志出口记录异常类型和代码，后续回调继续执行，原事务返回值不变。日志不可用时沿用该出口的降级机制。
- 没有事务时立即执行，沿用相同异常隔离规则；事务失效且仅允许回滚时拒绝登记。
- 提交抛出异常或驱动返回 `false`、回执未知、普通跨通道部分提交或 XA 未决时，不执行成功回调。XA 后台恢复不会补执行内存回调。
- 协程退出会主动回滚未结束事务并丢弃回调，回调捕获管理器形成循环引用也不依赖循环 GC 清理。
- 回调存于当前事务管理器内存，沿用现有协程上下文边界。进程退出、提交成功后回调执行前崩溃，都可能丢失回调。

回调用于提交后加速操作。需要可靠送达的工作须先在业务事务中持久化，并由恢复机制兜底，不能只依赖内存回调。
