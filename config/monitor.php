<?php

// +----------------------------------------------------------------------
// | 慢请求监控配置
// +----------------------------------------------------------------------

declare(strict_types=1);

return [
  // 是否启用慢请求监控（总开关），修改后需重启服务生效
  'enabled' => false,
  // 启用时显式指定 TraceWriterInterface 实现，或由应用服务提供者绑定该接口。
  // 需要文件日志时配置 Viswoole\Monitor\Writer\JsonTraceWriter::class。
  'writer' => null,
  // 慢请求阈值（毫秒），达到该值才写入记录；0 表示记录全部请求（开发调试用）
  'threshold_ms' => 1000,
  'request_id' => [
    // 默认 bin2hex(random_bytes(16))，重复概率极低但非零；严格唯一请传入自己的发号器。
    // 支持任何无参 PHP callable：闭包、函数名、静态方法、实例方法数组、可调用对象。
    // 返回 string|int；生成失败跳过本次监控，不降级成随机 ID 破坏自定义唯一性承诺。
    'generator' => null,
    // 非空标头名称启用，null 或空串关闭；采集中的快请求也会返回 ID。
    'response_header' => null,
  ],
  // 请求关闭时仅保留方法/路径；响应关闭时仍保留状态、字节数和完成信息。
  'capture_request' => true,
  'capture_response' => true,
  'capture_database' => true,
  'capture_cache' => true,
  // 不采集的路径列表，支持 fnmatch 通配符；单次逐段解码和尾斜线处理与路由一致，大小写遵循 router.case_sensitive。
  // 排除健康检查、静态资源等无需观测的路径，避免为高 QPS 无价值请求支付采集成本
  // 完整路径及去伪静态后缀候选任一命中即排除，采集开始前采用更严格的保护并集。
  'exclude_paths' => ['/health*', '/favicon.ico', '/static/*', '/assets/*'],
  // 单请求响应正文采集上限（字节），超限默认不输出正文，可配置 body_truncator 保留业务摘要。
  // 常规 JSON API 响应远小于 8KB，调低可减半每请求的正文累积内存与脱敏成本
  'max_body_bytes' => 8192,
  // callable(string $body, array $context): ?array；仅超限时调用，返回待脱敏摘要。
  // context: path、method、direction(request/response)、content_type、is_complete、max_bytes。
  // 流式正文可能只有前缀，未知 method 为空串。
  // 摘要按脱敏后实际字节校验：脱敏替换标记与编码开销可能使摘要膨胀超过 max_bytes，
  // 此时整体回退占位而非局部截断，处理器应让摘要远小于 max_bytes。
  // 返回 null、异常或摘要超限均回退占位。例如：
  // static function (string $body, array $context): ?array {
  //   if (!$context['is_complete'] || $context['content_type'] !== 'application/json') { return null; }
  //   $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
  //   return is_array($decoded) ? array_intersect_key($decoded, ['code' => true, 'message' => true]) : null;
  // }
  'body_truncator' => null,
  // 单请求操作记录条数上限，超限后计入 dropped_operations
  'max_operations' => 200,
  // 请求快照总 JSON 字节预算，最低 16 KiB；包含固定结构与汇总预留。
  'max_snapshot_bytes' => 262144,
  // 位置绑定无法可靠推断敏感字段，默认隐藏全部绑定值
  'record_bindings' => false,
  // 默认仅记录 SQL 的 SHA-256 摘要；开启原文后，SQL 字面量和注释可能含敏感信息。
  // 此开关与 record_bindings 独立，不使用正则猜测各数据库方言的字面量边界。
  'record_sql' => false,
  // 追加业务敏感字段，不替换内置规则；body 可隐藏请求/响应整组正文，
  // 输出字段的脱敏不会改变数据库/缓存的内部统计分类。
  'sensitive_keys' => [],
  // 按请求 path 追加字段，支持通配符；所有命中项与内置/全局规则取并集。
  // 复用排除路径的单次解码、尾斜线和 router.case_sensitive 语义。
  // 完整路径及去后缀候选命中的规则取并集，带点的真实路径也可能获得额外保护。
  'sensitive_keys_by_path' => [
    // '/api/auth/*' => ['code', 'mobile'],
    // '/api/private/*' => ['body'],
  ],
];
