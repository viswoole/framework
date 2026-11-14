<?php

declare(strict_types=1);

namespace Viswoole\Monitor;

use Viswoole\Monitor\Http\UriSanitizer;

/** 响应头采集状态；预算跨更新共享，内部始终保存数组，输出时才脱敏容器。 */
final class ResponseHeaders
{
  private const int MAX_HEADERS = 100;
  /** @var array<string,string> 已按字段脱敏、按小写名称索引的标头 */
  private array $headers = [];
  private int $bytes = 0;
  private bool $hasTruncated = false;
  private readonly int $maxBytes;
  private readonly PayloadSanitizer $sanitizer;

  /**
   * 创建响应头采集器
   *
   * 复用当前请求的脱敏器，避免响应头遗漏路径级规则。
   *
   * @param MonitorOptions $options 采集配置
   * @param SnapshotBudget|null $budget 当前请求共享预算
   * @param PayloadSanitizer|null $sanitizer 已解析当前路径规则的脱敏器
   */
  public function __construct(MonitorOptions $options, private readonly ?SnapshotBudget $budget = null, ?PayloadSanitizer $sanitizer = null)
  {
    $this->maxBytes = min(max(4096, $options->maxBodyBytes), intdiv($options->maxSnapshotBytes, 4));
    $this->sanitizer = $sanitizer ?? new PayloadSanitizer($options);
  }

  /**
   * 记录成功设置的标头；同名覆盖释放旧预算，超限时省略整个新值。
   * @param string $key 标头名称
   * @param string $value 标头值
   * @return void
   */
  public function record(string $key, string $value): void
  {
    $key = strtolower($key);
    $this->remove($key);
    // 先检查原始长度，避免为超大标头创建额外的日志副本。
    if (strlen($key) + strlen($value) > $this->maxBytes) {
      $this->hasTruncated = true;
      return;
    }
    // Location 内部的敏感参数不是标头字段名，需先清理 URL 再应用字段规则。
    $value = $this->sanitizer->sanitizeField($key, $key === 'location' ? UriSanitizer::sanitize($value) : $value);
    $cost = strlen($key) + strlen($value);
    if (count($this->headers) >= self::MAX_HEADERS || $this->bytes + $cost > $this->maxBytes) {
      $this->hasTruncated = true;
      return;
    }
    // 原始长度用于局部标头限额；共享 JSON 预算必须包含转义后的真实成本。
    $encodedCost = SnapshotBudget::size([$key => $value]) + 1;
    if ($this->budget !== null && !$this->budget->replace('header:' . $key, $encodedCost)) {
      $this->hasTruncated = true;
      return;
    }
    $this->headers[$key] = $value;
    $this->bytes += $cost;
  }

  /**
   * 生成独立日志快照；headers 规则可以隐藏整组，不改变采集器的内部类型。
   * @return array{headers:mixed,headers_truncated:bool} 标头摘要
   */
  public function snapshot(): array
  {
    // 逐条记录时已完成字段级脱敏，这里仅应用 headers 整组隐藏规则，
    // 不再递归重洗已脱敏数据（避免双重清洗开销）
    $headers = $this->sanitizer->redactContainer('headers', $this->headers);
    return [
      'headers' => $headers,
      'headers_truncated' => $this->hasTruncated || (is_array($headers) && isset($headers['__truncated'])),
    ];
  }

  /** @param string $key 归一化的标头名 @return void 移除过期的采集值并归还预算。 */
  private function remove(string $key): void
  {
    if (!array_key_exists($key, $this->headers)) { return; }
    $this->bytes -= strlen($key) + strlen($this->headers[$key]);
    unset($this->headers[$key]);
    $this->budget?->release('header:' . $key);
  }
}
