<?php

declare(strict_types=1);

namespace Viswoole\Tests\Database;

use Swoole\Coroutine;
use Viswoole\Database\Channel;
use Viswoole\Database\Query\Options;
use Viswoole\Database\Raw;

/**
 * 带 IO 挂起点的通道替身
 *
 * execute() 中通过 Coroutine::sleep 模拟真实数据库的网络 IO 挂起，
 * 用于复现多协程并发查询下的数据竞争问题。
 */
class SleepChannel extends Channel
{
  public function __construct(
    private readonly \PDOStatement $statement,
    private readonly float $delaySeconds
  ) {}

  public function execute(
    string|Raw   $sql,
    array        $bindings = [],
    false|string $getId = false,
    bool         $master = false
  ): \Swoole\Database\PDOStatementProxy|\PDOStatement|int|string {
    if ($this->delaySeconds > 0) Coroutine::sleep($this->delaySeconds);
    return $this->statement;
  }

  public function pop(string $type): mixed
  {
    return null;
  }

  public function put(mixed $connect): void {}

  public function build(Options $options): Raw
  {
    return new Raw('SELECT id, user_id FROM t');
  }
}
