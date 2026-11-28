<?php

/**
 * 职责：声明异步任务存储、租约、重试及有效期；不承载业务处理逻辑。
 */

declare(strict_types=1);

return [
  // 任务队列持久化使用的缓存商店名称，需在 config/cache.php 的 stores 中已定义。
  // null 表示跟随 cache.default 指定的默认通道。
  // 持久化队列要求驱动支持任务状态与索引的原子条件提交，内置驱动中仅 Redis 支持。
  // 默认通道为 File 时必须指定 Redis 商店；非持久化任务不受此限制。
  // 注意：切换商店前残留在旧商店中的队列条目不会被新商店恢复，切换时需确保队列已清空。
  'store' => env('task.store'),

  // 可指定稳定且唯一的部署实例 ID；未指定时每次启动生成随机标识。
  // 恢复会扫描同一缓存商店内所有实例分组，包括旧 Worker 分组。
  'instance_id' => env('task.instance_id'),

  // 投递与执行租约（秒），运行期间每约三分之一周期自动续租。
  // 实例崩溃后，租约到期的任务由每秒恢复扫描接管，再按失败退避重试；租约过短会放大重复投递窗口，建议不低于 10。
  // 协程任务按 Task Worker 数 × 此预算计算节点持久化任务上限；非协程模式固定每进程1个。
  // 容量包括投递中和执行中任务，不包括 emitWait 与非持久化直投。
  // 有效取值为 1 到 1024 的整数；通过环境变量设置时由框架归一化为整数后校验。
  'concurrency_per_worker' => (int)(env('task.concurrency_per_worker') ?: 8),

  'lease_seconds' => (int)(env('task.lease_seconds') ?: 30),

  // 次数包含首次执行；投递失败不消耗执行次数。
  // base_seconds 为首次失败的退避基数（指数翻倍），max_seconds 为退避上限。
  'retry' => [
    'max_attempts' => (int)(env('task.retry.max_attempts') ?: 5),
    'base_seconds' => (int)(env('task.retry.base_seconds') ?: 5),
    'max_seconds' => (int)(env('task.retry.max_seconds') ?: 300),
  ],

  // 任务默认有效期（秒），从投递时刻起算，任务被消费时检测；
  // 已过期的持久化任务进入失败队列，不执行处理器并返回 false。
  // 0 或 null 表示长期有效。
  // 仅对 emit() 投递的任务生效；emitWait()/emitsWait() 为同步执行，不受此配置影响。
  'expire' => env('task.expire'),

  // 按主题覆盖任务有效期（秒），优先级高于 expire 全局默认，主题名不区分大小写。
  // 值为 null 表示该主题长期有效，可用于全局设置了过期时间时为特定主题豁免。
  'topics' => [
    // 'sms.sendLoginCode' => 300,
    // 'log.write' => null,
  ]
];
