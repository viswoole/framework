# 监控隐私配置

`monitor.privacy` 将“是否采集分组”和“隐藏业务字段”分开；隐私设置不会改变真实请求或响应。

```php
'privacy' => [
  'defaults' => [
    'request' => [
      'body' => ['capture' => true, 'redact_fields' => ['phone']],
      'query' => ['capture' => true, 'redact_fields' => ['phone']],
      'headers' => ['capture' => true, 'redact_fields' => []],
    ],
    'response' => [
      'body' => ['capture' => true, 'redact_fields' => ['phone']],
      'headers' => ['capture' => true, 'redact_fields' => []],
    ],
  ],
  'rules' => [
    [
      'paths' => ['/admin/auth/profile/phone'],
      'methods' => ['PUT'],
      'request' => ['body' => ['redact_fields' => ['code', 'oldCode']]],
    ],
    [
      'paths' => ['/admin/service-configs/*'],
      'request' => ['body' => ['capture' => false], 'query' => ['capture' => false]],
      'response' => ['body' => ['capture' => false]],
    ],
  ],
],
```

## 分组

| 分组 | 作用 |
|---|---|
| `request.body` | 原始请求正文和 `post` 副本共用策略 |
| `request.query` | 查询参数 |
| `request.headers` | 请求标头 |
| `response.body` | 响应正文，包含自定义裁剪摘要 |
| `response.headers` | 响应标头 |
| `request.metadata` | 方法、路径、路由参数及其他请求元信息，仅支持 `redact_fields` |
| `response.metadata` | HTTP 状态、完成标记及其他响应元信息，仅支持 `redact_fields` |
| `operation.metadata` | 数据库、缓存等操作记录，仅支持 `redact_fields` |

`metadata` 是对既有元信息脱敏能力的明确分组，不允许关闭整个元信息组。隐藏敏感路由参数时，对应日志展示路径也整体脱敏，真实分发不变。

## 唯一语义

- `capture=false`：该分组不采集。请求正文不调用 `rawContent()`，`post` 同时禁用；响应正文不累积、不解析，也不调用裁剪处理器。
- `redact_fields`：递归匹配该分组内的完整字段名。大小写、下划线、连字符及首尾空白统一后完全匹配；`oldCode`、`old_code`、`old-code` 相同，`code` 不匹配 `business_code`。
- `redact_fields=['body']` 只隐藏业务数据中名为 body 的字段，不影响正文容器或响应方向。
- 内置敏感字段见 `Fields::BUILT_IN`，始终参与匹配；不再使用子串猜测，业务新增凭据字段必须显式配置完整名称。
- 禁用分组使用 `[CAPTURE_DISABLED]`，DTO 状态为 `NOT_CAPTURED`；字段脱敏使用 `[REDACTED]`，状态为 `REDACTED`。正文仍可保留实际已发送字节数等非内容元信息。

## 合并与匹配

省略的采集开关默认为 true，字段列表默认为空。内置保护、defaults 和全部命中 rules 取并集：任意 false 否决采集，字段取并集，不存在后写覆盖。

`paths` 必填、非空，支持 fnmatch。匹配遵循路由的大小写配置、尾斜线处理、单次逐段解码和伪静态候选并集；不把编码斜线变成路径分隔符。`methods` 省略表示所有方法，填写时必须是非空列表，方法按大写比较。

已有 `capture_request` / `capture_response` 是方向总开关；总开关关闭时，分组配置不能重新开启采集。正文类型白名单、预算和操作记录开关继续生效。

## 从旧配置迁移

`monitor.sensitive_keys`、`monitor.sensitive_keys_by_path` 已移除，即使空数组也会触发启动期迁移错误，禁止新旧规则并存。

- 将旧的全局业务字段分别放入需要保护的方向与分组的 `defaults.*.*.redact_fields`，需要保护操作绑定时同时配置 `operation.metadata`。
- 将路径映射转换为 `rules` 列表，并明确方向、分组及可选方法。
- 旧的 body/post 整组隐藏替换为 `request.body.capture=false`；响应正文使用 `response.body.capture=false`。
- 旧的 query/headers 整组隐藏替换为对应方向与分组的 `capture=false`。
- 旧子串规则需要枚举真实字段，例如 `password` 与 `oldPassword` 是两个不同字段；旧机制覆盖过的高频复合变体（`confirm_password`、`auth_token`、`x-token`、`secret_key` 等）已经内置，其余业务凭据自行补充完整名称。

配置错误和未知键在启动时拒绝。修改配置后需重启常驻服务。

## 独立正文调用

`forScope(Target::REQUEST_BODY, 'POST')->sanitizeBody(...)` 默认继承请求方向和 POST 方法，无需再传相同 context。`sanitizeBody()` 与 `truncateBody()` 共用上下文解析，裁剪回调也继承已知路径、方向和方法。显式提供 `direction` 或 `method` 可为本次调用覆盖设置，不会修改原脱敏器；未指定任何请求方向的独立脱敏器仍默认按响应正文处理。
