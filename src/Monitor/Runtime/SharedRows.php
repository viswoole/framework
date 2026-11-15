<?php

declare(strict_types=1);

/* 职责：封装固定容量共享行，并允许测试注入写入故障。 */

namespace Viswoole\Monitor\Runtime;

use RuntimeException;
use Swoole\Table;

/** 每行最多 8192 字节，所有读写由 SharedStateStore 的同一锁保护。 */
class SharedRows
{
  private Table $table;

  /**
   * 主进程分配双缓冲及提交行
   * @param int $capacity 规则上限
   * @throws RuntimeException 创建失败
   */
  public function __construct(int $capacity)
  {
    $this->table = new Table(self::chunkCapacity($capacity) * 2 + 1);
    $this->table->column('payload', Table::TYPE_STRING, 8192);
    if (!$this->table->create()) { throw new RuntimeException('监控共享表创建失败'); }
  }

  /**
   * 计算单缓冲最大分块数
   * @param int $capacity 最大规则数
   * @return int 每规则最多 4096 字节，另预留全局头及数组分隔符
   */
  public static function chunkCapacity(int $capacity): int
  {
    return (int)ceil(($capacity * 4096 + 512) / 8192);
  }

  /**
   * 写入一个有界行
   * @param string $key 行键
   * @param string $payload 原始字节
   * @return bool 是否成功
   */
  public function write(string $key, string $payload): bool
  {
    return strlen($payload) <= 8192 && $this->table->set($key, ['payload' => $payload]);
  }

  /**
   * 读取已存在行
   * @param string $key 行键
   * @return string 完整字节
   * @throws RuntimeException 提交状态缺失
   */
  public function read(string $key): string
  {
    $value = $this->table->get($key, 'payload');
    if (!is_string($value)) { throw new RuntimeException('监控共享配置缺少行: ' . $key); }
    return $value;
  }
}
