-- 职责：仅隔离快照仍一致的损坏记录，不反序列化原始数据。
local isMissing = ARGV[6] == 'missing'
local current = redis.call('GET', KEYS[1])
if isMissing then
  if current ~= false then return 0 end
else
  if current ~= ARGV[1] then return 0 end
  if redis.call('EXISTS', KEYS[2]) == 1 then return 0 end
end
for index = 3, 6 do
  local expected = index <= 4 and 'set' or (index == 5 and 'zset' or 'hash')
  if index ~= 3 or not isMissing then
    local kind = redis.call('TYPE', KEYS[index]).ok
    if kind ~= 'none' and kind ~= expected then return redis.error_reply('Invalid queue quarantine index type') end
  end
end
local groupKey = redis.call('HGET', KEYS[6], ARGV[3])
local groupTag = redis.call('HGET', KEYS[6], ARGV[3] .. ':tag')
if groupKey then
  local kind = redis.call('TYPE', groupKey).ok
  if kind ~= 'none' and kind ~= 'set' then return redis.error_reply('Queue group must be a set') end
  if not groupTag then return redis.error_reply('Queue directory tag is missing') end
end
if not isMissing then
  redis.call('SET', KEYS[2], ARGV[2])
  redis.call('SADD', KEYS[3], ARGV[4])
  redis.call('SADD', KEYS[4], ARGV[5])
end
redis.call('DEL', KEYS[1])
redis.call('ZREM', KEYS[5], ARGV[3])
redis.call('HDEL', KEYS[6], ARGV[3], ARGV[3] .. ':tag')
if groupKey then
  redis.call('SREM', groupKey, ARGV[3])
  if redis.call('SCARD', groupKey) == 0 then
    redis.call('DEL', groupKey)
    redis.call('SREM', KEYS[4], groupTag)
  end
end
return 1
