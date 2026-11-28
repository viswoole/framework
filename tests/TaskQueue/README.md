# Redis 可靠任务队列

任务实现集中在 `src/Core/Server/Task/`，命名空间为 `Viswoole\Core\Server\Task`；公共门面仍使用 `Viswoole\Core\Facade\Task`。任务类只提供当前命名空间，不保留旧类名入口。

持久化任务使用至少一次投递模型：执行前只取得执行租约，处理器正常返回后 ACK 删除。异常、进程退出或停机中断均保留恢复入口。业务已完成但 ACK 前退出时可能重复执行，处理器应按 `queue_id` 或业务唯一键实现幂等。所有部署实例应保持系统时间同步，租约提交会在 Redis 内再次检查到期时间。

主题必须为非空字符串，`"0"` 合法；批量投递会将 PHP 自动转成整数的主题数组键还原为字符串。首次持久化要求配置的序列化器返回字符串；返回 `false`、`null` 等类型时在写入记录和索引前抛出异常。失败原因以 Base64 字节字段保存在 JSON 元数据中，显式读取完整记录时通过 `lastError` 原样还原，支持非 UTF-8、空字符串及零字节；重放会清除旧失败原因。

## 生命周期与配置

| 状态 | 后续行为 |
| --- | --- |
| dispatched | 等待 Swoole Task Worker；投递租约到期可重新投递 |
| running | 执行期间自动续租；正常结束 ACK 删除 |
| retry | 到达 retryAt 后恢复投递 |
| failed | 不自动投递，等待查询、人工重放或删除 |

`task.store` 必须指向支持 `QueueStoreInterface` 的 Redis 商店，监控装饰器透传原子操作。任务采用带版本的 JSON 元数据信封，业务载荷沿用首次持久化的序列化字节，状态更新不会重新序列化业务对象。状态、索引增删、分组目录和空分组回收在同一 Lua 脚本中提交；每次认领生成独立令牌，过期消费者不能续租、ACK 或修改失败状态。同一令牌的并发续租按 Redis 当前期限判断执行权，不使 ACK 或失败提交误报冲突。到期索引也随状态变更原子更新，包含续租、重试、失败、ACK 和重放；常规空扫描不读取未到期任务载荷。

- `task.lease_seconds`：默认 30 秒，投递和执行分别获得完整租约。执行期间每约三分之一周期续租；每秒从到期时间有序集合读取最多 100 条待恢复任务。
- `task.retry.max_attempts`：默认 5，包含首次执行。处理器异常和执行租约到期计入次数；Swoole 投递失败不计入执行次数。
- `task.retry.base_seconds`：默认 5 秒，按执行次数指数增长。
- `task.retry.max_seconds`：默认 300 秒。实际延时为指数退避上限值的 80%～100%；扫描调度可能使实际投递稍晚。
- `task.expire` / `task.topics`：保留既有格式，从原始投递时间计算有效期；过期任务、未注册主题直接进入失败队列。

持久化投递链路统一传输元数据与 `queuePayload` 原始序列化字节；首次和恢复投递都不在生产者或恢复者中还原业务对象。取得执行租约后，Task Worker 才解码一次业务载荷，再构造 `TaskProxy`。显式调用失败记录或普通缓存读取接口仍会按商店规则解码数据。

处理器正常返回 `false`、`null` 也会 ACK；需要重试时抛出异常。`finish()` 仍只向 Swoole 调用方回传结果，不提前 ACK。`emitWait()`、`emitsWait()` 不参与持久化队列。`emitWait(): mixed` 保留原生返回值类型，包括数组、整数、布尔值和通过 `finish(null)` 回传的空值。

```php
use Viswoole\Core\Facade\Task;
use Viswoole\Core\Server\Task\TaskProxy;

Task::register('report.build', function (TaskProxy $task): array {
  // 分阶段执行耗时工作时，可主动确认仍持有租约。
  if (!$task->renewLease()) {
    throw new RuntimeException('报告任务已失去执行租约');
  }
  return ['ok' => true];
});
```

自动续租依赖 Swoole 事件循环能够调度，阻塞代码和密集 CPU 计算可能推迟续期；应按实际最长不可调度区间设置租约，必要时在阶段之间主动续租。租约丢失不能撤销已经发生的业务副作用。

**投递受理契约（破坏性变更）：**

- `emit(..., queue: true): string|false`：持久化提交得到确认后返回稳定队列 ID（48 位十六进制字符串），不再返回 Swoole 整数 ID。受理入口不发起本机首次 IPC；共享队列由有容量的节点领取，本机 IPC 失败不撤销受理，框架保留恢复入口。
- 停机期间返回 `false`，表示本次调用未受理；未知主题、配置、序列化及提交前读取错误仍抛普通异常，尚未受理。
- Redis 原子命令未取得可靠回执时抛 `Viswoole\Core\Server\Task\TaskAcceptanceUnknownException`，`queueId` 保存本次尝试身份，原始故障保留在异常链中。此时可能已受理，禁止按普通失败同步降级或创建新任务；应保留业务身份进行核对。原任务可能已完成并 ACK，查不到队列记录也不能单独证明未受理。
- `emit(..., queue: false): int|false` 保持原生 Swoole 投递结果（包括任务 ID 0）及异常，不提供持久化恢复。
- 调用方必须同步调整返回类型，且先识别受理未知异常，再处理普通的明确失败。稳定队列 ID 只标识这次入队，不提供跨调用业务去重；至少一次执行仍需业务幂等。

低层 `TaskQueue::enqueue()` 仅返回已确认持久化的 ID，`delivery()` 单独读取 IPC 信封。组合接口 `persist()` 保留供底层调用使用，其读取阶段异常不表示入队失败。自定义 QueueStoreInterface 实现必须将可能已提交的故障报告为 QueueCommitUnknownException，不能以普通异常伪装明确拒绝。

类名注册只扫描公开的非魔术方法。静态方法直接调用，实例方法在每次任务调用时通过容器 `invokeClass()` 解析构造依赖，不缓存处理器对象。恢复投递失败日志只记录任务标识、主题与执行次数，不写入业务载荷。

## 只读元数据查询

```php
use Viswoole\Core\Facade\Task;
use Viswoole\Core\Server\Task\TaskQueueFilter;
use Viswoole\Core\Server\Task\TaskQueueState;

$query = Task::queue();
$filter = new TaskQueueFilter([
  'producerInstanceId' => Task::instanceId(),
  'topic' => 'sms.send',
  'state' => TaskQueueState::Retry,
]);
$page = $query->list($filter, cursor: '', limit: 20);
if ($page['cursor'] !== '') {
  $nextPage = $query->list($filter, cursor: $page['cursor'], limit: 20);
}
$counts = $query->count($filter);
$detail = $query->get($queueId);

// 当前实例正在执行的任务；可与投递实例条件组合。
$executing = $query->list(new TaskQueueFilter([
  'executionInstanceId' => Task::instanceId(),
]));
```

条件按 AND 匹配；实例身份区分大小写，主题不区分大小写。空条件查询同商店全部实例的持久化任务。`producerInstanceId` 始终保留首次投递实例；`executionInstanceId`、`executionWorkerId` 在认领执行权时随租约原子更新，跨实例接管后体现实际消费者，不沿用投递实例。低层 `consume()` 未提供执行身份时，查询中的执行归属字段为 null，不猜测归属。

等待投递、重试、失败任务的执行归属为 null。运行租约到期但尚未恢复扫描时，持久化 `state` 仍为 running，`executionLeaseExpired=true`，查询中的执行归属为 null，按执行实例筛选也排除该记录。`leaseUntil`、`observedAt` 使用 Redis 时间；此信息表示租约有效性，不是实例存活探针。重放保留原投递身份、生成新队列 ID，并清空执行归属。

列表返回 `list` 和 `cursor`，每页上限 100；每次最多扫描 1000 个索引成员。稀疏条件可能得到空列表及非空 cursor，必须继续翻页直到 cursor 为空。游标只能配合同一筛选条件使用；不要把空 cursor 再当“下一页”查询，否则会从头开始。列表及统计不提供跨页一致性快照；并发新增、删除和状态变化可能影响结果。数量统计返回 total、dispatched、running、retry、failed，按同一筛选条件分批扫描，复杂度 O(商店任务数)，适用于管理查询，不宜每次业务请求都调用。

详情和列表只读取独立元数据 Hash 与有序 ID 索引，不读取业务载荷键、不调用反序列化器，不返回业务 data、queuePayload、执行令牌或比较版本。失败原因属于元数据，仍可能含异常消息，展示给终端用户前须按应用权限和脱敏规则处理。ACK、失败删除、重放、损坏隔离和缺失数据回收均在生产 Lua 中同步维护查询索引。已完成、已删除及隔离任务不在此查询入口中保留；隔离记录仍使用独立入口查询。非持久化任务不在列表中。

查询索引从此版本的入队及状态提交开始建立，不扫描或回填旧存储。启用前按既有协议切换约束排空旧队列或使用独立商店前缀，不能将新索引查询为空作为旧队列已排空的依据。QueueStoreInterface 新增 queryQueueMetadata/inspectQueueMetadata，自定义存储实现须同步提供只读查询能力。

## 失败任务管理

```php
$failed = Task::failedQueue();
$records = $failed->list(page: 1, pageSize: 20);
$record = $failed->get($queueId);
$newQueueId = $failed->retry($queueId);
$deleted = $failed->delete($queueId);
```

失败记录包含原始业务载荷、`attempts`、`failedAt`、`lastError` 和投递时间，默认永久保留。列表按 ID 排序，每页最多 100 条；并发变化时分页不提供快照保证。当前实现读取失败集合后分页，适用于有限规模的运维管理。

重放会原子移除失败记录并创建新任务 ID，保留 `originalQueueId`，重置次数和有效期起点，由恢复扫描投递。同一记录并发重放只有一个成功；已不存在或竞争失败返回 `false`。

## 损坏记录隔离

恢复时发现信封、元数据或载荷编码损坏，将使用异常携带的原始字节做比较后原子隔离。存储网络故障不会触发隔离；隔离写入失败时保留原数据，下轮重试。同一快照只能有一个隔离胜者，并发修复或更新后的新版本不会被误删。

隔离会同步移除到期索引、原分组成员和分组目录；数据已丢失时也使用相同的原子索引回收路径。隔离记录默认保留，可查询原始字节、原因和隔离时间：

```php
$quarantined = Task::quarantinedQueue();
$records = $quarantined->list(page: 1, pageSize: 20);
$record = $quarantined->get($queueId);
$rawBase64 = $record === null ? null : base64_encode($record['raw']);
```

`raw` 为未解码的二进制快照，`reason` 为失败原因，`quarantinedAt` 为隔离时间戳；读取不会恢复快照中的业务对象。商店内部对快照和原因独立使用 Base64，JSON 序列化商店也可保留任意原始字节。列表按记录键排序，每页最多 100 条，管理查询不提供并发快照保证。隔离记录不会自动重放。

## 接口与存储契约

任务类型统一使用 `Viswoole\Core\Server\Task`。`consume(array $data)` 只接收投递载荷，`dispatch(string $queueId, Closure $dispatch)` 只接收标识与投递函数。

队列只接受当前版本的信封记录和原子维护的到期索引，不识别、扫描或迁移历史分组及历史载荷。格式不受支持时，状态读取和更新会抛出携带原始快照的解析异常；恢复流程按快照隔离损坏记录，继续处理本轮其他到期任务。普通缓存的序列化契约不受队列协议限制。

更换不兼容的存储协议前，应先排空原队列，或为新协议使用独立的 `task.store` 商店及键前缀。

## 已知边界（已评估不修）

以下边界经稳定性审查评估后刻意保留，属“已评估不修”而非遗漏；后续变更触及触发条件时应重新评估：

- **业务对象反序列化信任域**：队列载荷按设计恢复业务对象类，安全边界是同应用的商店命名空间（各节点部署相同业务代码、对象类型）。仅当 `task.store` 改为多租户共享 Redis 时，才需要为队列记录增加签名校验。
- **失败/隔离列表全量载入 ID 排序**：`TaskFailedQueue::list()` 与 `TaskQuarantinedQueue::list()` 内存开销随记录数线性增长。两者均依赖人工清理，规模可控；仅当失败记录长期超过 10 万条时，才考虑演进存储端分页。
- **查询统计游标循环无防御上限**：`TaskQueueQuery::count()` 依赖内部 `queryQueueMetadata` 的游标终止契约，不加迭代上限。仅当允许第三方自定义 `QueueStoreInterface` 驱动实现（外部代码可能违反游标契约）时，才需要防死循环保护。

## 测试

需要 PHP >= 8.4、Swoole、phpredis、POSIX 扩展和独立测试 Redis。使用随机键前缀，只清理测试自己的键，不执行 FLUSHDB。

这些测试使用项目已有的 PHPUnit，属于真实 PHP/Swoole/Redis 集成测试，不依赖 Node、TypeScript 或 pnpm。

在仓库根目录运行：

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php tests/TaskQueue
```

默认连接 `127.0.0.1:6379` 的独立测试 Redis；远程 Redis 可设置 `QUEUE_TEST_REDIS_HOST`。场景使用当前 PHPUnit 的 PHP 解释器启动独立子进程，避免全局桩、fork 和 Swoole reactor 污染测试运行器。运行器通过启动握手确认独立会话，场景超时或结束后清理整个进程组并检查进程树，避免遗留子进程。子进程失败或超时会使测试失败，并报告退出码和错误输出。

本机没有 PHP 时，可使用下文的隔离镜像。仓库只读挂载，PHPUnit 缓存写入容器临时目录：

```sh
docker run --rm --network host -v "$PWD:/workspace:ro" -w /workspace --entrypoint php viswoole-shutdown-test:swoole-6.2.5 vendor/bin/phpunit --bootstrap vendor/autoload.php --cache-directory /tmp/phpunit-cache tests/TaskQueue
```

镜像需要能够访问独立测试 Redis；按实际网络设置 `QUEUE_TEST_REDIS_HOST`，例如通过 `-e QUEUE_TEST_REDIS_HOST=host.docker.internal` 访问宿主机。

PHPUnit 断言真实 Redis 的成功 ACK、异常退避、次数耗尽、失败队列重放、八进程消费竞争、过期令牌拒绝提交、真实进程退出接管和跨越原租约的自动续期；另覆盖续租与 ACK/失败/续租交错、带 `__wakeup()` 的对象消费与重放、非法配置重复拒绝及配置原子更新，并保留启动日志故障和现有停机回归。新增契约测试覆盖真实同步返回类型、依赖注入及实例隔离；调度测试断言空扫描无 GET/EXISTS、每轮上限和索引一致性；运行器测试验证超时后后代进程已退出。可靠性边界测试覆盖首批 100 条全部损坏后健康任务继续恢复、隔离并发修复、多进程竞争、写入失败保留原数据、二进制隔离记录、元数据类型损坏、数据缺失入口回收，以及普通缓存空键与入队和 ACK 共存。真实 Swoole IPC 测试覆盖 `__wakeup()` 与 `__serialize()` 对象的首次和重试观测一致。协议测试验证不支持的记录明确拒绝、不会恢复其中的业务对象，也不会影响普通缓存或本轮其他健康任务。

`TaskQueue` 构造器只接收商店与租约秒数，不再包含索引清理日志回调。重试配置完整校验后才替换原策略，管理器也只缓存初始化成功的队列。

`EncodingBoundaryTest.php` 覆盖非字符串序列化结果拒绝且没有存储副作用、真实 JSON 编码失败、二进制异常退避、失败重放与删除、再次执行时替换失败原因、旧令牌拒绝覆盖，以及空主题拒绝和主题 `"0"` 的消费与 ACK。`ContractFixTest.php` 另验证主题 `"0"` 的真实 Swoole 同步、批量和协程批量投递。

## 服务停止回归

`ShutdownTest.php` 使用临时目录和无端口映射的独立 Swoole 服务，验证 11 秒任务完成后才正常退出、Swoole 超时中断提示和框架超时强杀进程树。该文件不需要 Redis，可单独运行 `vendor/bin/phpunit --bootstrap vendor/autoload.php tests/TaskQueue/ShutdownTest.php`；容器运行方式与上文相同。

服务默认启用 `reload_async=true`、`max_wait_time=60` 秒，可由 `server.options` 或服务级 `options` 调整窗口。停止和 `server:start --force` 都等待实际启动窗口的两倍再加 5 秒，然后才执行框架强停。实际启动配置和退出结果保存在 PID 文件旁的 `.shutdown.json`，不依赖部署后已修改的配置。正常退出仅表示已经进入 Task 和请求回调的工作均已结束，不表示业务执行成功；Swoole 中断仍在处理的任务、框架强杀以及旧实例缺少回执时都输出警告。

Worker 退出时仅清理框架队列恢复定时器，停止期间拒绝新任务投递和恢复重投。运行中的持久化任务由独立续租定时器保护；处理器被超时中断后停止续租，任务在执行租约到期后进入退避重试。恢复定时器停止不会清理仍在运行任务的续租定时器。在 Linux 上，强停会核对 `/proc` 启动标识并清理捕获的子孙进程；无 `/proc` 平台只跟踪主进程，不能提供相同的进程树清理保证。

### Swoole 稳定版隔离升级验证

已复现 Swoole 6.0.2 的上游问题：Server 配置 `max_wait_time=15`，但 Task Worker 进程池仍使用独立的 3 秒默认值，11 秒任务会提前中断。位置为上游 `src/server/process.cc::Factory::kill_task_workers()` 调用 `ProcessPool::shutdown()`，后者采用进程池自己的 `max_wait_time`。框架不能通过延长 CLI 等待修复该扩展行为；测试不会跳过或隐藏这个失败。

官方稳定版 6.2.5 已改变这条停机路径。以下验证镜像只替换独立镜像中的 Swoole，不更新业务容器、不改框架的 Composer 版本约束：

```sh
docker build -t viswoole-shutdown-test:swoole-6.2.5 tests/TaskQueue/docker
docker run --rm --network none -v "$PWD:/workspace:ro" --entrypoint php viswoole-shutdown-test:swoole-6.2.5 /workspace/tests/TaskQueue/fixtures/shutdown-scenarios.php
```

运行 PHPUnit 时使用 `viswoole-shutdown-test:swoole-6.2.5` 镜像和上面的容器命令，无需额外配置 PHP 子进程命令或场景路径。该镜像仅用于停机行为验证；生产升级前仍需对照现有镜像保留 Swoole 的编译功能，并在预发布环境验证业务。没有执行业务镜像替换或发布。

`AcceptanceTest.php` 覆盖首次 IPC 返回 false/异常、提交后读取失败、补偿写失败、真实 Lua 提交后的响应丢失、提交前断线、无确认返回、序列化拒绝、停机拒收以及恢复后的单次认领和 ACK。

连接获取失败与命令回执丢失分别验证：TIME、GET 成功后获取 EVAL 连接失败时，保留普通 RedisException，并断言 EVAL 未调用且存储没有新增记录；进入 EVAL 后发生故障仍保守报告受理结果未知。

`QueryTest.php` 验证投递与执行实例区分、组合筛选、数量统计、游标分页、过期租约归属、元数据只读、监控装饰器透传，以及 ACK、重放、删除、隔离和缺失回收的索引一致性。

### 查询索引损坏时的只读降级

列表额外返回 `errors: list<{queueId, reason}>` 与 `isComplete`。单条 JSON、结构、身份或失败原因编码损坏，以及 ID 索引有成员但缺少元数据时，跳过该条并报告身份，游标继续前进；健康任务仍可查看。`isComplete` 只表示本次扫描没有发现损坏，不表示已经翻完所有页，也不提供并发快照保证。调用方遍历分页时须汇总所有页的 errors。

统计同样返回 errors 和 isComplete，数量只包含可正确读取且匹配的任务；isComplete=false 时是已知有效记录数量，不能展示成精确总数。损坏记录无法可靠判断筛选归属时也会报告，不静默假定其不匹配。单条详情损坏仍抛出包含 queueId 的明确异常，不把损坏伪装成不存在。查询不会删除、隔离或重建数据，存储连接故障仍正常抛出。

`QueryCorruptionTest.php` 覆盖损坏 JSON、非对象、非有限数字、未知状态、非法租约、失败原因编码损坏、身份不一致、元数据缺失、统计不完整、游标继续及查询前后 Redis 字节不变。

## 共享队列与多节点运行时

`ClusterDispatchTest` 用随机 Redis 前缀测试容量 CAS、错误节点拒收、租约过期跨节点接管及人工重投身份关联。`ClusterRuntimeTest` 启动两个真实多进程 Swoole HTTP 服务，验证单生产节点的任务被双方执行、节点容量上限、强制终止执行节点后的恢复、原生停机排空及本机同步结果。所有子进程均通过独立进程组运行器校验并清理，结束后删除随机测试键。

受理测试使用共享停机标记验证拒收；原生服务停机由真实生命周期场景验证，不对未启动的原生 Server 调用停机统计接口。


### 持续入队的扫描公平性

`RecoveryFairnessTest` 使用真实 Redis 覆盖持续入队、其他节点移除前缀及未知主题不断到达的组合。领取成功后从扫描偏移增量中扣除本机已移出的成员，不能把移除前的 OFFSET 当成新排名，也不能每批成功后重复遍历全部未知主题前缀。需要绕过未知主题时，以 Redis 时间固定本轮到期上界，窗口结束后回绕；新到达任务不能无限延长当前扫描。时间上界只约束查询，不修改共享任务的原定时间或重试时刻。

`QueueStoreInterface::getDueQueue()` 新增可选 `dueBefore` 参数，Redis 和监控包装器均透传。自定义驱动实现需同步增加此可选参数；默认空值保留现有实时到期查询，传未来值也不能提前读取尚未到期的任务。此机制保证持续进展，不承诺跨节点严格 FIFO 或业务副作用恰好一次。

`RecoveryScanCostTest` 验证稳定未知主题前缀不会按“前缀条数 × 正常任务条数”放大读取成本；计数真实元数据读取，不以机器负载敏感的墙钟时间作为单测断言。
