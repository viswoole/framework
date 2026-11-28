-- 职责：按当前信封协议原子校验执行权或版本，并同步任务、分组与到期索引。
local expected = cjson.decode(ARGV[1])
local prefix = ARGV[8]
local current = redis.call('GET', KEYS[1])
local stored = nil
if current == false then
  if expected ~= cjson.null then return 0 end
else
  if expected == cjson.null then return 0 end
  if string.sub(current, 1, #prefix) ~= prefix then
    return redis.error_reply('Invalid queue record format')
  end
  stored = cjson.decode(string.sub(current, #prefix + 1))
  local metadata = stored.metadata
  if tonumber(ARGV[7]) > 0 then
    -- 并发续租不改变执行权，ACK 和失败提交应按当前令牌与期限判断。
    if metadata.queueToken ~= expected.queueToken or metadata.queueState ~= expected.queueState then return 0 end
    local now = redis.call('TIME')
    if tonumber(now[1]) + tonumber(now[2]) / 1000000 >= metadata.leaseUntil then return 0 end
  elseif metadata.queueRevision ~= expected.queueRevision then return 0 end
end
-- Lua 出错不会回滚先前写入，类型检查和信封编解码必须先于任何写操作。
for index = 2, 4 do
  local isUsed = (index == 2 and ARGV[4] ~= '') or (index == 3 and ARGV[5] ~= '')
    or (index == 4 and (ARGV[4] ~= '' or ARGV[5] ~= ''))
  if isUsed then
    local kind = redis.call('TYPE', KEYS[index]).ok
    if kind ~= 'none' and kind ~= 'set' then return redis.error_reply('Queue index must be a set') end
  end
end
local directoryKind = redis.call('TYPE', KEYS[7]).ok
if directoryKind ~= 'none' and directoryKind ~= 'hash' then
  return redis.error_reply('Queue directory must be a hash')
end
local scheduleKind = redis.call('TYPE', KEYS[6]).ok
if scheduleKind ~= 'none' and scheduleKind ~= 'zset' then
  return redis.error_reply('Queue schedule must be a sorted set')
end
for index = 8, 9 do
  local expectedType = index == 8 and 'hash' or 'zset'
  local kind = redis.call('TYPE', KEYS[index]).ok
  if kind ~= 'none' and kind ~= expectedType then return redis.error_reply('Invalid queue query index type') end
end
if KEYS[5] ~= KEYS[1] and redis.call('EXISTS', KEYS[5]) == 1 then return 0 end
local value = ARGV[2]
local nextValue = nil
if value ~= '' then nextValue = cjson.decode(string.sub(value, #prefix + 1)) end
if nextValue ~= nil and stored ~= nil then
  nextValue.payload = stored.payload
  local before = stored.metadata
  local after = nextValue.metadata
  if after.queueState == 'running' and before.queueState == 'running' and after.queueToken == before.queueToken then
    after.leaseUntil = math.max(after.leaseUntil, before.leaseUntil)
  end
  value = prefix .. cjson.encode(nextValue)
end
-- 节点容量与任务状态必须在同一个原子提交中检查和维护，不能只依赖领取前的数量快照。
for index = 10, 11 do
  local kind = redis.call('TYPE', KEYS[index]).ok
  if kind ~= 'none' and kind ~= 'zset' then return redis.error_reply('Queue assignments must be a sorted set') end
end
local function hasAssignment(record)
  return record and type(record.dispatchInstanceId) == 'string' and record.dispatchInstanceId ~= ''
    and (record.queueState == 'dispatched' or record.queueState == 'running') and record.leaseUntil > 0
end
local nextMetadata = nextValue and nextValue.metadata or nil
local clock = redis.call('TIME')
local observedAt = tonumber(clock[1]) + tonumber(clock[2]) / 1000000
local capacity = tonumber(ARGV[9]) or 0
if capacity > 0 and hasAssignment(nextMetadata) then
  local existing = tonumber(redis.call('ZSCORE', KEYS[11], ARGV[6])) or 0
  if existing <= observedAt and redis.call('ZCOUNT', KEYS[11], '(' .. observedAt, '+inf') >= capacity then return 0 end
end
if stored and hasAssignment(stored.metadata) then
  redis.call('ZREM', KEYS[10], ARGV[3])
  if redis.call('ZCARD', KEYS[10]) == 0 then redis.call('DEL', KEYS[10]) end
end
if hasAssignment(nextMetadata) then
  redis.call('ZREMRANGEBYSCORE', KEYS[11], '-inf', observedAt)
  redis.call('ZADD', KEYS[11], nextMetadata.leaseUntil, ARGV[6])
  -- 租约续期同步延长容量索引寿命；节点消失后索引自行回收，不能提前于其他任务租约失效。
  local last = redis.call('ZREVRANGE', KEYS[11], 0, 0, 'WITHSCORES')
  redis.call('PEXPIREAT', KEYS[11], math.ceil(tonumber(last[2]) * 1000) + 1000)
end
-- 查询投影和业务状态同一原子提交，ACK、删除及重放不得留下旧身份。
redis.call('HDEL', KEYS[8], ARGV[3])
redis.call('ZREM', KEYS[9], ARGV[3])
if nextValue ~= nil then
  redis.call('HSET', KEYS[8], ARGV[6], cjson.encode(nextValue.metadata))
  redis.call('ZADD', KEYS[9], 0, ARGV[6])
end
if KEYS[5] ~= KEYS[1] then redis.call('DEL', KEYS[1]) end
if value == '' then redis.call('DEL', KEYS[1])
else redis.call('SET', KEYS[5], value) end
if ARGV[4] ~= '' then
  redis.call('HDEL', KEYS[7], ARGV[3], ARGV[3] .. ':tag')
  redis.call('SREM', KEYS[2], ARGV[3])
  if redis.call('SCARD', KEYS[2]) == 0 then
    redis.call('DEL', KEYS[2])
    redis.call('SREM', KEYS[4], ARGV[4])
  end
end
if ARGV[5] ~= '' then
  redis.call('HSET', KEYS[7], ARGV[6], KEYS[3], ARGV[6] .. ':tag', ARGV[5])
  redis.call('SADD', KEYS[3], ARGV[6])
  redis.call('SADD', KEYS[4], ARGV[5])
end
redis.call('ZREM', KEYS[6], ARGV[3])
if nextValue ~= nil and nextValue.metadata.queueState ~= 'failed' then
  local metadata = nextValue.metadata
  local due = math.max(tonumber(metadata.leaseUntil) or 0, tonumber(metadata.retryAt) or 0)
  redis.call('ZADD', KEYS[6], due, ARGV[6])
end
return 1
