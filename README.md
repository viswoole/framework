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
