<?php

/**
 * 职责：编码队列元数据与不可变载荷的存储信封；不恢复业务对象或决定状态转换。
 */
declare(strict_types=1);

namespace Viswoole\Cache\Driver\Redis;

use JsonException;
use RuntimeException;

/**
 * 用 JSON 暴露 Lua 所需元数据，以原始字节保留业务对象的序列化结果。
 */
final class QueueRecord
{
  /**
   * @var string 队列存储协议标识，避免与普通缓存值混淆
   */
  public const string PREFIX = "\0viswoole:queue:1:";

  /**
   * 判定缓存值是否为队列信封
   * @param mixed $value 缓存原始值
   * @return bool 是否为队列信封
   */
  public static function isEncoded(mixed $value): bool
  {
    return is_string($value) && str_starts_with($value, self::PREFIX);
  }

  /**
   * 提取不包含业务载荷的状态元数据
   * @param array $record 完整队列记录
   * @return array 状态元数据
   * @throws RuntimeException 失败原因类型无效时抛出
   */
  public static function metadata(array $record): array
  {
    unset($record['data'], $record['queuePayload']);
    if (array_key_exists('lastError', $record)) {
      if (!is_string($record['lastError'])) { throw new RuntimeException('队列失败原因必须为字符串'); }
      // 异常消息可能包含任意字节，不能直接进入 JSON 元数据。
      $record['lastErrorBase64'] = base64_encode($record['lastError']);
      unset($record['lastError']);
    }
    return $record;
  }

  /**
   * 在显式读取时还原失败原因，状态提交仍使用可安全 JSON 编码的元数据。
   * @param array $metadata 状态元数据
   * @return array 携带原始失败原因字节的记录
   * @throws RuntimeException 失败原因编码损坏时抛出
   */
  public static function restoreError(array $metadata): array
  {
    if (!array_key_exists('lastErrorBase64', $metadata)) { return $metadata; }
    $encoded = $metadata['lastErrorBase64'];
    $reason = is_string($encoded) ? base64_decode($encoded, true) : false;
    // 规范编码保证管理读取后重新编码仍可参与同一版本的条件提交。
    if ($reason === false || base64_encode($reason) !== $encoded) { throw new RuntimeException('队列失败原因编码无效'); }
    unset($metadata['lastErrorBase64']);
    $metadata['lastError'] = $reason;
    return $metadata;
  }

  /**
   * 编码新版本元数据并保留原始载荷字节
   * @param array $record 新队列记录
   * @param string $payload 首次持久化的完整序列化记录
   * @return string 队列存储信封
   * @throws JsonException 元数据无法编码时抛出
   * @throws RuntimeException 失败原因类型无效时抛出
   * @throws \Random\RandomException 无法生成版本号时抛出
   */
  public static function encode(array $record, string $payload): string
  {
    $metadata = self::metadata($record);
    $metadata['queueRevision'] = bin2hex(random_bytes(16));
    return self::PREFIX . json_encode([
      'metadata' => $metadata,
      'payload' => base64_encode($payload),
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * 读取信封，不反序列化其中的业务对象
   * @param string $value 队列信封
   * @return array{metadata: array, payload: string} 元数据和原始序列化载荷
   * @throws JsonException 信封 JSON 损坏时抛出
   * @throws RuntimeException 信封结构或载荷编码损坏时抛出
   */
  public static function decode(string $value): array
  {
    $envelope = json_decode(substr($value, strlen(self::PREFIX)), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($envelope) || !is_array($envelope['metadata'] ?? null)
      || !is_string($envelope['payload'] ?? null)) {
      throw new RuntimeException('队列存储信封结构无效');
    }
    $payload = base64_decode($envelope['payload'], true);
    if ($payload === false) { throw new RuntimeException('队列载荷编码无效'); }
    self::validateMetadata($envelope['metadata']);
    return ['metadata' => $envelope['metadata'], 'payload' => $payload];
  }
  /**
   * 拒绝缺字段或类型损坏的状态，避免无效任务永久占据到期批次。
   * @param array $metadata 状态元数据
   * @return void
   * @throws RuntimeException 元数据不符合当前协议时抛出
   */
  public static function validateMetadata(array $metadata): void
  {
    if (array_key_exists('data', $metadata) || array_key_exists('queuePayload', $metadata)) {
      throw new RuntimeException('队列元数据不能包含业务载荷字段');
    }
    foreach (['queueId', 'queueGroup', 'queueToken', 'queueRevision', 'topic'] as $field) {
      if (!is_string($metadata[$field] ?? null)) { throw new RuntimeException('队列元数据字段无效：' . $field); }
    }
    foreach (['producerInstanceId', 'executionInstanceId', 'dispatchInstanceId'] as $field) {
      if (isset($metadata[$field]) && (!is_string($metadata[$field]) || $metadata[$field] === '')) {
        throw new RuntimeException('队列实例身份无效：' . $field);
      }
    }
    if (isset($metadata['executionWorkerId']) && (!is_int($metadata['executionWorkerId']) || $metadata['executionWorkerId'] < 0)) {
      throw new RuntimeException('队列执行 Worker 身份无效');
    }
    if ($metadata['topic'] === '') { throw new RuntimeException('队列主题不能为空'); }
    if (array_key_exists('lastError', $metadata)) { throw new RuntimeException('队列失败原因必须使用字节编码'); }
    self::restoreError($metadata);
    if (!in_array($metadata['queueState'] ?? null, ['dispatched', 'running', 'retry', 'failed'], true)) {
      throw new RuntimeException('队列状态无效');
    }
    if (!is_int($metadata['attempts'] ?? null) || $metadata['attempts'] < 0) {
      throw new RuntimeException('队列执行次数无效');
    }
    foreach (['leaseUntil', 'retryAt'] as $field) {
      $value = $metadata[$field] ?? ($field === 'retryAt' ? 0 : null);
      if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) {
        throw new RuntimeException('队列时间字段无效：' . $field);
      }
    }
  }
}
