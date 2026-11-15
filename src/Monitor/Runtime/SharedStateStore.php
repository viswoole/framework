<?php

declare(strict_types=1);

/* 职责：使用短原子提交区发布双缓冲状态；转换及 JSON 编解码均在锁外执行。 */

namespace Viswoole\Monitor\Runtime;

use OverflowException;
use RuntimeException;
use Swoole\Coroutine;

/** 缓存不可变版本；并发管理更新通过版本复核重试，不能丢失其他进程更新。 */
final class SharedStateStore implements StateStore
{
  private ProcessMutex $lock;
  private SharedRows $rows;
  private RuntimeState $cached;

  /**
   * 在 fork 前初始化共享资源
   * @param float $thresholdMs 静态阈值
   * @param int $capacity 规则上限
   * @param SharedRows|null $rows 可测试共享行
   */
  public function __construct(float $thresholdMs, private int $capacity, ?SharedRows $rows = null)
  {
    $this->lock = new ProcessMutex();
    $this->rows = $rows ?? new SharedRows($capacity);
    $this->cached = RuntimeState::initial($thresholdMs);
    $this->commit($this->cached, $this->prepare($this->cached), 0);
  }

  /** @return RuntimeState 复制已提交原始字节后，在锁外解码并构建索引 */
  public function read(): RuntimeState
  {
    $this->lock->acquire();
    try { $frame = $this->copyPublished(); }
    finally { $this->lock->release(); }
    if ($frame instanceof RuntimeState) { return $frame; }
    $this->cached = StateCodec::decode($frame);
    return $this->cached;
  }

  /**
   * 锁外转换，发布前复核版本；冲突后重新应用纯转换
   * @param callable(RuntimeState):RuntimeState $change 框架内部纯转换，可能重试
   * @return RuntimeState 已提交状态
   * @throws RuntimeException 持续并发更新超过一秒或争锁超时
   */
  public function update(callable $change): RuntimeState
  {
    $deadline = hrtime(true) + 1_000_000_000;
    do {
      $before = $this->read();
      $next = $change($before);
      if ($next === $before) { return $before; }
      $chunks = $this->prepare($next);
      if ($this->tryPublish($before, $next, $chunks)) { return $next; }
      if (hrtime(true) >= $deadline) { throw new RuntimeException('监控配置并发更新重试超时（1s）'); }
      // 避免持续冲突时，一个管理协程独占 worker 反复转换大配置。
      if (Coroutine::getCid() >= 0) { Coroutine::sleep(0.001); }
      else { usleep(1000); }
    } while (true);
  }

  /**
   * 仅在锁内复制活动缓冲，避免发布者复用缓冲时撕裂读取
   * @return RuntimeState|string 已缓存状态或完整已提交字节
   */
  private function copyPublished(): RuntimeState|string
  {
    $commit = $this->readCommit();
    if ($this->cached->version === $commit['version']) { return $this->cached; }
    $json = '';
    for ($index = 0; $index < $commit['chunks']; $index++) {
      $json .= $this->rows->read($commit['bank'] . ':' . $index);
    }
    return $json;
  }

  /**
   * 锁外完成全量编码及容量校验
   * @param RuntimeState $state 待提交配置
   * @return list<string> 有界分块
   * @throws OverflowException 超出启动分配容量
   */
  private function prepare(RuntimeState $state): array
  {
    $chunks = str_split(StateCodec::encode($state), 8192);
    if (count($chunks) > SharedRows::chunkCapacity($this->capacity)) {
      throw new OverflowException('监控配置超过共享编码容量');
    }
    return $chunks;
  }

  /**
   * 原子复核并发布，失败不改变活动缓冲
   * @param RuntimeState $before 转换依据
   * @param RuntimeState $next 新状态
   * @param list<string> $chunks 已编码配置
   * @return bool 是否提交，false 表示版本冲突
   */
  private function tryPublish(RuntimeState $before, RuntimeState $next, array $chunks): bool
  {
    $this->lock->acquire();
    try {
      $commit = $this->readCommit();
      if ($commit['version'] !== $before->version) { return false; }
      $this->commit($next, $chunks, $commit['bank']);
      return true;
    } finally { $this->lock->release(); }
  }

  /** @return array{bank:int,chunks:int,version:int} 数十字节的原子提交描述 */
  private function readCommit(): array
  {
    return json_decode($this->rows->read('commit'), true, flags: JSON_THROW_ON_ERROR);
  }

  /**
   * 仅将准备好的字节复制到非活动缓冲并切换提交行
   * @param RuntimeState $state 新配置
   * @param list<string> $chunks 已编码分块
   * @param int $activeBank 原活动区
   * @return void
   * @throws RuntimeException 缓冲或提交失败
   */
  private function commit(RuntimeState $state, array $chunks, int $activeBank): void
  {
    $nextBank = 1 - $activeBank;
    foreach ($chunks as $index => $chunk) {
      if (!$this->rows->write($nextBank . ':' . $index, $chunk)) { throw new RuntimeException('监控配置缓冲写入失败'); }
    }
    $commit = json_encode(['bank' => $nextBank, 'chunks' => count($chunks), 'version' => $state->version], JSON_THROW_ON_ERROR);
    if (!$this->rows->write('commit', $commit)) { throw new RuntimeException('监控配置提交失败'); }
    $this->cached = $state;
  }
}
