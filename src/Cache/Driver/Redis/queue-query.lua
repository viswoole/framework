-- 职责：只读独立元数据索引，每轮最多检查 1000 条，不读取业务载荷键。
local now = redis.call('TIME')
local observedAt = tonumber(now[1]) + tonumber(now[2]) / 1000000
-- 单条结构损坏仅报告该身份，不能中断其他健康记录；查询不执行修复写入。
local function decodeMetadata(raw, id)
  if not raw then return nil, 'Query metadata is missing' end
  local ok, metadata = pcall(cjson.decode, raw)
  if not ok or type(metadata) ~= 'table' then return nil, 'Invalid metadata JSON object' end
  local queueId = string.match(id, '^s:48:"([a-f0-9]+)";$')
  if not queueId or metadata.queueId ~= queueId then return nil, 'Metadata identity mismatch' end
  local state = metadata.queueState
  if state ~= 'dispatched' and state ~= 'running' and state ~= 'retry' and state ~= 'failed' then
    return nil, 'Invalid metadata state'
  end
  if type(metadata.topic) ~= 'string' or metadata.topic == '' or type(metadata.leaseUntil) ~= 'number'
    or metadata.leaseUntil < 0 or metadata.leaseUntil ~= metadata.leaseUntil or metadata.leaseUntil == math.huge then
    return nil, 'Invalid metadata topic or lease'
  end
  metadata.observedAt = observedAt
  return metadata, nil
end
if ARGV[1] == 'detail' then
  local raw = redis.call('HGET', KEYS[1], ARGV[2])
  if not raw then
    if redis.call('ZSCORE', KEYS[2], ARGV[2]) then return redis.error_reply('Query metadata is missing') end
    return ''
  end
  local metadata, reason = decodeMetadata(raw, ARGV[2])
  if not metadata then return redis.error_reply(reason) end
  return cjson.encode(metadata)
end
local filter = cjson.decode(ARGV[3])
local minimum = ARGV[2] == '' and '-' or '(' .. ARGV[2]
local ids = redis.call('ZRANGEBYLEX', KEYS[2], minimum, '+', 'LIMIT', 0, 1000)
local result = {}
local errors = {}
local last = ''
for _, id in ipairs(ids) do
  last = id
  local raw = redis.call('HGET', KEYS[1], id)
  local metadata, reason = decodeMetadata(raw, id)
  if not metadata then
    table.insert(errors, {id, reason})
  else
    local matches = true
    for field, value in pairs(filter) do
      local actual = metadata[field == 'state' and 'queueState' or field]
      if field == 'topic' and type(actual) == 'string' then actual = string.lower(actual) end
      if field == 'executionInstanceId' and (metadata.queueState ~= 'running' or metadata.leaseUntil <= observedAt) then
        actual = cjson.null
      end
      if actual ~= value then matches = false break end
    end
    if matches then
      metadata.observedAt = observedAt
      -- 非标准 JSON 数字等也可能在重新编码时失败，仍须按单条报告。
      local ok, encoded = pcall(cjson.encode, metadata)
      if ok then
        table.insert(result, {id, encoded})
        if #result >= tonumber(ARGV[4]) then break end
      else
        table.insert(errors, {id, 'Metadata JSON encoding failed'})
      end
    end
  end
end
local cursor = ''
if last ~= '' and #redis.call('ZRANGEBYLEX', KEYS[2], '(' .. last, '+', 'LIMIT', 0, 1) > 0 then
  cursor = last
end
return {result, cursor, errors}
