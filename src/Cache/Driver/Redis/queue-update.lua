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
